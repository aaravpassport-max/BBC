<?php
/**
 * Migration 18: Soft-delete for individual form-schema versions
 *
 * ROOT-CAUSE FINDING: FormBuilderController::delete() only ever deactivates
 * EVERY version of a category's schema at once (is_active=0 across the
 * whole category) — there was no way to remove one specific saved version
 * from a category's history at all. The version-history panel
 * (resources/views/admin/forms/edit.php) can only ever grow: every save()
 * creates a new row, and nothing ever removed one. rto_form_schemas itself
 * has no deleted_at column (see migration 1's "reserved... not yet active"
 * comment and migration 17's audit-column follow-up, neither of which added
 * one), so there was no column to even express "this specific version was
 * removed" separately from "this specific version is not the currently
 * active one" (is_active=0 already means the latter for every past
 * version).
 *
 * Adds deleted_at the same way every other soft-delete in this codebase
 * works (rto_leads, rto_ratings — see migration 15) — idempotent via
 * Migration::addColumn(), never a hard DELETE.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddFormSchemaSoftDelete extends Migration
{
    public function up(): void
    {
        $this->addColumn('rto_form_schemas', 'deleted_at',
            'DATETIME NULL AFTER updated_at');
        $this->addIndex('rto_form_schemas', 'idx_category_deleted', 'category, deleted_at');
    }

    public function down(): void
    {
        // Deliberately a no-op — dropping a column that may hold real
        // admin-entered deletion timestamps is not a safe rollback, same
        // reasoning as every other soft-delete migration in this set.
    }
}
