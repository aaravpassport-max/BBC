<?php
/**
 * Migration: Fix Missing Columns & Add Color Customization
 *
 * Fixes all column gaps identified in the Phase 1 review:
 * - rto_vendors.pending_balance
 * - rto_payments.tds_rate
 * - rto_complaints.admin_response, client_id alias
 * - rto_leads.form_data JSON column for rich form submissions
 * - rto_leads.vehicle_number, owner_name for quick access
 * - rto_form_submissions table for detailed intake data
 * - rto_color_settings table for per-section color customization
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class FixMissingColumns extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        // ── rto_vendors: add pending_balance ─────────────────────────────
        $this->addColumn($p . 'rto_vendors', 'pending_balance',
            'DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total_jobs');

        // ── rto_payments: add tds_rate ────────────────────────────────────
        $this->addColumn($p . 'rto_payments', 'tds_rate',
            'DECIMAL(5,2) NOT NULL DEFAULT 2.00 AFTER tds_amount');

        // ── rto_complaints: admin_response + client_id alias ─────────────
        $this->addColumn($p . 'rto_complaints', 'admin_response',
            'LONGTEXT NULL AFTER resolution_note');
        $this->addColumn($p . 'rto_complaints', 'responded_at',
            'DATETIME NULL AFTER admin_response');
        // Add client_id as alias for complainant_id for backward compatibility
        $this->addColumn($p . 'rto_complaints', 'client_id',
            'BIGINT UNSIGNED NULL AFTER complainant_id');

        // Backfill client_id from complainant_id
        $wpdb->query("UPDATE {$p}rto_complaints SET client_id = complainant_id WHERE client_id IS NULL");

        // ── rto_leads: rich form data columns ────────────────────────────
        $this->addColumn($p . 'rto_leads', 'form_data',
            'LONGTEXT NULL COMMENT "JSON snapshot of full form submission"');
        $this->addColumn($p . 'rto_leads', 'vehicle_number',
            'VARCHAR(30) NULL AFTER form_data');
        $this->addColumn($p . 'rto_leads', 'owner_name',
            'VARCHAR(200) NULL AFTER vehicle_number');
        $this->addColumn($p . 'rto_leads', 'rto_state',
            'VARCHAR(100) NULL AFTER owner_name');
        $this->addColumn($p . 'rto_leads', 'rto_office',
            'VARCHAR(200) NULL AFTER rto_state');
        $this->addColumn($p . 'rto_leads', 'tracking_token',
            'VARCHAR(64) NULL UNIQUE AFTER rto_office');
        $this->addColumn($p . 'rto_leads', 'challan_number',
            'VARCHAR(50) NULL AFTER tracking_token');
        $this->addColumn($p . 'rto_leads', 'rto_submission_date',
            'DATETIME NULL AFTER challan_number');
        $this->addColumn($p . 'rto_leads', 'estimated_completion',
            'DATE NULL AFTER rto_submission_date');

        // ── rto_vendors: add stats columns ──────────────────────────────
        $this->addColumn($p . 'rto_vendors', 'kyc_document_path',
            'VARCHAR(500) NULL AFTER kyc_status');

        // ── rto_form_submissions: full intake data ───────────────────────
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}rto_form_submissions (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id     BIGINT UNSIGNED NOT NULL,
            category    VARCHAR(50)  NOT NULL COMMENT 'Driving License|RC Services|HP|NOC|Vehicle|Commercial|Other',
            sub_service VARCHAR(200) NOT NULL,
            form_data   LONGTEXT     NOT NULL COMMENT 'Complete JSON of all submitted fields',
            raw_post    LONGTEXT     NULL     COMMENT 'Original POST data (sanitised)',
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lead(lead_id),
            INDEX idx_category(category)
        ) " . $wpdb->get_charset_collate());

        // ── rto_service_categories: master category table ────────────────
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}rto_service_categories (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            slug        VARCHAR(100) NOT NULL,
            name        VARCHAR(200) NOT NULL,
            icon        VARCHAR(10)  NOT NULL DEFAULT '🔑',
            description TEXT         NULL,
            display_order INT         NOT NULL DEFAULT 0,
            is_active   TINYINT      NOT NULL DEFAULT 1,
            UNIQUE KEY slug(slug)
        ) " . $wpdb->get_charset_collate());

        // Add category_id to services
        $this->addColumn($p . 'rto_services', 'category_id',
            'INT NULL AFTER category');

        // ── Color settings stored as individual wp_options ───────────────
        // (No separate table needed — stored via update_option)

        update_option('rtoflow_migration_fix_columns', '1.0');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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
        // Columns intentionally not dropped on rollback to preserve data.
        // To reverse manually: DROP COLUMN on rto_vendors.pending_balance,
        // rto_payments.tds_rate, rto_complaints.admin_response, etc.
    }
}
