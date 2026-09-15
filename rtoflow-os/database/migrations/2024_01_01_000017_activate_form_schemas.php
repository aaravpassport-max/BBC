<?php
/**
 * Migration 10: Activate Dynamic Form Engine (P11-DEAD-005 follow-up)
 *
 * rto_form_schemas was created in migration 1 ("reserved for dynamic form
 * builder — not yet active") with columns id/service_id/name/steps_json/
 * version/is_active/created_at, but had zero real callers: the public apply
 * form (resources/views/public/apply.php) is static PHP/HTML with the
 * fields for each service hand-coded per branch.
 *
 * This migration does not change the shape of steps_json (it already stores
 * a LONGTEXT JSON blob, which is exactly what FormEngineService needs to
 * hold an ordered list of field definitions), it only adds the audit
 * columns every other "admin-configurable engine" table in this codebase
 * carries (see rto_eligibility_rules.created_by/updated_at) so a form
 * schema's edit history is visible the same way.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class ActivateFormSchemas extends Migration
{
    public function up(): void
    {
        // ── rto_form_schemas: audit + activation columns ─────────────────
        $this->addColumn('rto_form_schemas', 'created_by',
            'BIGINT UNSIGNED NULL AFTER is_active');
        $this->addColumn('rto_form_schemas', 'updated_by',
            'BIGINT UNSIGNED NULL AFTER created_by');
        $this->addColumn('rto_form_schemas', 'updated_at',
            'DATETIME NULL AFTER created_at');

        // A service should have at most one active schema at a time so
        // FormRepository::find_by_service()'s ->first() is deterministic
        // rather than depending on row order.
        $this->addIndex('rto_form_schemas', 'idx_service_active', 'service_id, is_active');
    }

    public function down(): void
    {
        // Deliberately a no-op: dropping columns that may already hold real
        // admin-entered data (created_by/updated_by/updated_at) is not a
        // safe rollback, and the table itself pre-dates this migration and
        // is owned by migration 1's down().
    }
}
