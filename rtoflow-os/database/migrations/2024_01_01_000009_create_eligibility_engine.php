<?php
/**
 * Migration 9: Eligibility Rules Engine (P1 — was completely missing)
 *
 * The audit found that "eligibility" fields shown on the public apply form
 * (apply.php's age/licence dropdowns) were decorative HTML with no server-side
 * evaluation anywhere in the codebase — grepping the whole backend for the
 * actual field names returned zero references. This migration creates the
 * schema for a genuine, reusable, admin-configurable eligibility engine:
 * one rule definition works for any service, evaluated the same way for
 * every service, rather than a new if/else block hand-written per service
 * every time the business needs a new eligibility condition.
 *
 * A rule is: for service X (or ALL services when service_id IS NULL), field
 * F must satisfy operator O against value V, or the applicant is blocked
 * (severity='block') or warned (severity='warn') with a configurable message.
 * This is intentionally the same "field / operator / value" shape used by
 * AutomationService::check_conditions() elsewhere in this codebase, so the
 * two engines share a mental model instead of inventing a second one.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateEligibilityEngine extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}rto_eligibility_rules (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            service_id      INT UNSIGNED NULL COMMENT 'FK rto_services.id; NULL = applies to every service',
            field_key       VARCHAR(64)  NOT NULL COMMENT 'Answer key from the apply form, e.g. applicant_age, has_valid_license, vehicle_type',
            field_label     VARCHAR(120) NOT NULL COMMENT 'Human-readable label for the admin UI, e.g. Applicant Age',
            operator        ENUM('equals','not_equals','gt','gte','lt','lte','in','not_in','not_empty') NOT NULL DEFAULT 'equals',
            value_json      TEXT NULL COMMENT 'JSON-encoded comparison value (scalar or array for in/not_in)',
            severity        ENUM('block','warn') NOT NULL DEFAULT 'block',
            fail_message    VARCHAR(500) NOT NULL COMMENT 'Shown to the applicant when this rule fails',
            priority        INT UNSIGNED NOT NULL DEFAULT 10,
            is_active       TINYINT(1)   NOT NULL DEFAULT 1,
            created_by      BIGINT UNSIGNED NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

            INDEX idx_service (service_id),
            INDEX idx_field   (field_key),
            INDEX idx_active  (is_active)
        ) {$c}");

        // Audit trail for eligibility results — lets an admin see, for any
        // rejected applicant, exactly which rule fired and why, rather than
        // a customer support ticket saying only "the site wouldn't let me apply".
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}rto_eligibility_checks (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            service_id      INT UNSIGNED NOT NULL,
            client_id       BIGINT UNSIGNED NULL COMMENT '0/NULL for anonymous pre-submission checks',
            answers_json    TEXT NOT NULL,
            eligible        TINYINT(1) NOT NULL,
            failed_rules_json TEXT NULL,
            checked_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_service (service_id),
            INDEX idx_client  (client_id),
            INDEX idx_checked (checked_at)
        ) {$c}");
    }

    public function down(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$p}rto_eligibility_checks");
        $wpdb->query("DROP TABLE IF EXISTS {$p}rto_eligibility_rules");
    }
}
