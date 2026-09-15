<?php
/**
 * Migration 13: Fix rto_notification_templates missing columns
 *
 * PART 5.4 — Help Centre audit, real bug found and fixed.
 *
 * EmailTemplateController::update() (app/Controllers/Admin/EmailTemplateController.php)
 * has, since the Email Templates screen was built, unconditionally written
 * `dlt_template_id` and `updated_at` on every single template save (all
 * three channels — email, SMS, WhatsApp), and both resources/views/admin/
 * email-templates/edit.php and index.php read `$tpl['description']` and
 * `$template['dlt_template_id']`. None of these three columns were ever
 * added to `rto_notification_templates` by any prior migration (verified
 * against 2024_01_01_000001_create_core_tables.php's original CREATE TABLE
 * and every later migration file — none of them touch this table).
 *
 * Consequence: because `$wpdb->update()` issues one SQL UPDATE naming ALL
 * of its columns in a single SET clause, MySQL rejects the ENTIRE statement
 * with "Unknown column 'dlt_template_id'" the moment that column doesn't
 * exist — meaning EVERY save of EVERY email/SMS/WhatsApp template (not just
 * SMS, where the DLT field is actually shown in the UI) has been silently
 * failing to persist ANY of its fields (subject, body, is_active included),
 * while the controller still redirects to ?saved=1 and the page shows a
 * false "Template saved successfully" banner. This is the real, root-cause
 * fix: add the three missing columns so the existing, already-written
 * update logic actually succeeds, rather than patching around it by
 * stripping fields from the UPDATE (which would silently drop the
 * DLT-template-ID feature the SMS channel's UI already promises).
 *
 * Idempotent via the same addColumn() SHOW-COLUMNS-guarded pattern used by
 * migration 3 (FixMissingColumns) — safe to run against a database that may
 * already have been hand-patched.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class FixEmailTemplateColumns extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = $p . 'rto_notification_templates';

        $this->addColumn($table, 'dlt_template_id',
            "VARCHAR(50) NULL COMMENT 'TRAI DLT registration ID, SMS channel only' AFTER variables");
        $this->addColumn($table, 'description',
            "VARCHAR(255) NULL COMMENT 'Admin-facing description of when this template is used' AFTER dlt_template_id");
        $this->addColumn($table, 'updated_at',
            "DATETIME NULL DEFAULT NULL AFTER description");

        update_option('rtoflow_migration_fix_email_template_columns', '1.0');
    }

    protected function addColumn(string $table, string $col, string $definition): void
    {
        global $wpdb;
        $exists = $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
        if (!$exists) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$definition}");
        }
    }

    public function down(): void
    {
        // Columns intentionally not dropped on rollback to preserve any
        // DLT IDs / descriptions already saved after this migration ran.
        // To reverse manually: DROP COLUMN dlt_template_id, description,
        // updated_at on rto_notification_templates.
    }
}
