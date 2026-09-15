<?php
/**
 * Migration 12: Category-level Form Schemas (Part 4.10 re-architecture)
 *
 * WHY THIS EXISTS: Parts 4.5-4.9 built the Form Builder around ONE schema
 * PER REAL SERVICE — 44 separate rows in rto_form_schemas, one per
 * "Learning License", "Transfer of Ownership", etc. After being shown a
 * reference plugin (gs23), the user made clear this is not the structure
 * they wanted: that plugin's "Documentation Services" inquiry form is ONE
 * form covering 21 distinct services in a single row — a service-picker
 * field plus every field for every service, each field's visibility gated
 * by a condition on that picker field's value. The real apply.php page
 * already has exactly this shape: 7 categories (Driving License, RC
 * Services, HP/Hypothecation, NOC, Vehicle Services, Commercial Vehicle,
 * Other Services), each with its own sub-service dropdown. So the correct
 * unit for one Form Builder "form" is a CATEGORY, not a service — 7 forms,
 * not 44.
 *
 * This migration adds the column that makes that possible without
 * discarding the existing service_id-based rows (nothing has run on a real
 * database yet in this engagement, so there is no live data to migrate,
 * but the column is additive and nullable either way — schemas keyed by
 * `category` and schemas keyed by `service_id` can coexist in the same
 * table without conflict; going forward, the Form Builder writes and reads
 * only `category`-keyed rows).
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddFormCategoryColumn extends Migration
{
    public function up(): void
    {
        $this->addColumn('rto_form_schemas', 'category',
            'VARCHAR(100) NULL AFTER service_id');

        // One active schema per category at a time, same determinism
        // guarantee migration 10 gave service_id lookups.
        $this->addIndex('rto_form_schemas', 'idx_category_active', 'category, is_active');
    }

    public function down(): void
    {
        // Deliberately a no-op — see migration 10's down() for the same
        // reasoning: dropping a column that may hold real admin-entered
        // category schemas is not a safe rollback.
    }
}
