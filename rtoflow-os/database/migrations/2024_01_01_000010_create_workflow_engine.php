<?php
/**
 * Migration 10: Data-Driven Workflow Engine (per-service transitions)
 *
 * Historically every lead — regardless of service — moved through the exact
 * same hard-coded lifecycle defined in WorkflowService::TRANSITIONS. That is
 * fine as a sane default, but it means a Driving Licence renewal and an RC
 * transfer are forced through an identical set of statuses even when the
 * business needs them to differ.
 *
 * This migration adds the schema for admin-configurable, per-service (or
 * global-default, when service_id IS NULL) workflow definitions:
 *
 *   rto_workflow_definitions — one row per named workflow, optionally scoped
 *     to a single service_id. Only active (is_active=1) definitions are used.
 *   rto_workflow_states      — the states that belong to a definition, with
 *     display ordering and a terminal flag (no outgoing transitions expected).
 *   rto_workflow_transitions — the allowed from -> to edges for a definition,
 *     each optionally gated by a required role, a guard condition, and a
 *     side-effect payload (both stored as JSON, mirroring the "field /
 *     operator / value" and action-list shapes already used elsewhere in
 *     this codebase by the eligibility engine and AutomationService).
 *
 * BACKWARD COMPATIBILITY: a service with no active workflow definition row
 * keeps using WorkflowService::TRANSITIONS exactly as before — this table
 * being empty is the normal, fully-supported state for every existing
 * install. See WorkflowEngineService for the fallback logic.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateWorkflowEngine extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}rto_workflow_definitions (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            service_id      INT UNSIGNED NULL COMMENT 'FK rto_services.id; NULL = global default workflow',
            name            VARCHAR(150) NOT NULL,
            is_active       TINYINT(1)   NOT NULL DEFAULT 1,
            created_by      BIGINT UNSIGNED NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

            INDEX idx_service (service_id),
            INDEX idx_active  (is_active)
        ) {$c}");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}rto_workflow_states (
            id                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            workflow_definition_id  INT UNSIGNED NOT NULL COMMENT 'FK rto_workflow_definitions.id',
            status_key              VARCHAR(64)  NOT NULL COMMENT 'Value stored in rto_leads.status when a lead is in this state',
            label                   VARCHAR(120) NOT NULL,
            is_terminal             TINYINT(1)   NOT NULL DEFAULT 0,
            display_order           INT UNSIGNED NOT NULL DEFAULT 0,

            INDEX idx_definition (workflow_definition_id),
            INDEX idx_status_key (status_key)
        ) {$c}");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}rto_workflow_transitions (
            id                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            workflow_definition_id  INT UNSIGNED NOT NULL COMMENT 'FK rto_workflow_definitions.id',
            from_status             VARCHAR(64)  NOT NULL,
            to_status               VARCHAR(64)  NOT NULL,
            requires_role           VARCHAR(32)  NULL COMMENT 'admin|staff|vendor|client; NULL = no role restriction',
            guard_condition_json    TEXT NULL COMMENT 'Optional JSON condition (field/operator/value) evaluated before allowing the transition',
            side_effect_json        TEXT NULL COMMENT 'Optional JSON list of actions to run on transition, same shape as AutomationService actions',

            INDEX idx_definition (workflow_definition_id),
            INDEX idx_from       (from_status)
        ) {$c}");
    }

    public function down(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$p}rto_workflow_transitions");
        $wpdb->query("DROP TABLE IF EXISTS {$p}rto_workflow_states");
        $wpdb->query("DROP TABLE IF EXISTS {$p}rto_workflow_definitions");
    }
}
