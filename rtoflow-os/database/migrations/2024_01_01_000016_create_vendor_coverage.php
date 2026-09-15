<?php
/**
 * Migration 10: Normalized Vendor Coverage Join Table (additive)
 *
 * VendorRepository::get_eligible() answers "which vendors cover city X and
 * service Y" with JSON_CONTAINS(cities,...) AND JSON_CONTAINS(services,...)
 * against the rto_vendors.cities/services JSON array columns. That works at
 * today's scale, but MySQL cannot use a normal index to satisfy
 * JSON_CONTAINS — every call is a full scan of rto_vendors, evaluating both
 * JSON_CONTAINS expressions per row. As the vendor count and the average
 * cities/services array length grow, this degrades linearly with table size
 * with no way to add an index that helps.
 *
 * This migration adds `rto_vendor_coverage`, a normalized (vendor_id,
 * city_id, service_id) join table with real indexes on every lookup
 * direction, and backfills it from the existing JSON columns. It is
 * intentionally additive: the JSON columns and the existing get_eligible()
 * query are left untouched. See VendorCoverageRepository for the
 * join-table-based read path (get_eligible_by_coverage()) and the
 * sync_vendor_coverage() method a future write-path integration would call
 * from VendorService::create()/update() to keep this table in sync going
 * forward. Cutting reads over to the new table, and wiring the write path,
 * are deliberately left for a separate change — see this migration's and
 * VendorCoverageRepository's docblocks for what that would involve.
 *
 * The `cities` and `services` JSON columns store a plain JSON array of
 * integer IDs, e.g. `[1,5,12]` — written via
 * wp_json_encode(array_map('intval', ...)) in VendorsController::store()/
 * update() and wp_json_encode($data['cities'] ?? []) in
 * VendorService::create(). The backfill below decodes exactly that shape.
 *
 * Idempotent:
 *   - Table creation uses CREATE TABLE IF NOT EXISTS.
 *   - Backfill is skipped entirely if rto_vendor_coverage already has any
 *     rows (cheap, correct for "did this migration already run" since the
 *     table has no other writer yet), and additionally uses INSERT IGNORE
 *     against the UNIQUE KEY so a partial/interrupted prior run can be
 *     safely re-run without duplicating rows.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateVendorCoverage extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_vendor_coverage (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vendor_id   BIGINT UNSIGNED NOT NULL,
            city_id     INT UNSIGNED NOT NULL,
            service_id  INT UNSIGNED NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_city_service (vendor_id, city_id, service_id),
            INDEX idx_vendor  (vendor_id),
            INDEX idx_city    (city_id),
            INDEX idx_service (service_id)
        ) {$c}");

        $this->backfill();
    }

    /**
     * Cross-product backfill: for every vendor, decode its cities[] and
     * services[] JSON arrays and insert one coverage row per (city,
     * service) pair. Skipped if the table is already populated.
     */
    protected function backfill(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_vendor_coverage");
        if ($existing > 0) {
            error_log('RTOFLOW Migration 10: rto_vendor_coverage already populated (' . $existing . ' rows) — skipping backfill.');
            return;
        }

        $vendors = $wpdb->get_results(
            "SELECT id, cities, services FROM {$p}rto_vendors
             WHERE cities IS NOT NULL AND services IS NOT NULL",
            ARRAY_A
        ) ?: [];

        $inserted = 0;
        $skipped  = 0;

        foreach ($vendors as $vendor) {
            $vendorId = (int) $vendor['id'];
            $cities   = json_decode($vendor['cities'] ?? '[]', true);
            $services = json_decode($vendor['services'] ?? '[]', true);

            if (!is_array($cities) || !is_array($services) || !$cities || !$services) {
                $skipped++;
                continue;
            }

            $rows = [];
            foreach ($cities as $cityId) {
                $cityId = (int) $cityId;
                if ($cityId <= 0) {
                    continue;
                }
                foreach ($services as $serviceId) {
                    $serviceId = (int) $serviceId;
                    if ($serviceId <= 0) {
                        continue;
                    }
                    $rows[] = $wpdb->prepare('(%d,%d,%d)', $vendorId, $cityId, $serviceId);
                }
            }

            if (!$rows) {
                $skipped++;
                continue;
            }

            // Batch per vendor to keep the INSERT statement size sane while
            // still being a single round-trip per vendor.
            foreach (array_chunk($rows, 500) as $chunk) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each
                // tuple was already produced via $wpdb->prepare() above.
                $result = $wpdb->query(
                    "INSERT IGNORE INTO {$p}rto_vendor_coverage (vendor_id, city_id, service_id) VALUES "
                    . implode(',', $chunk)
                );
                if ($result !== false) {
                    $inserted += $result;
                }
            }
        }

        error_log(sprintf(
            'RTOFLOW Migration 10: backfilled rto_vendor_coverage — %d row(s) inserted from %d vendor(s), %d vendor(s) skipped (empty/invalid cities or services).',
            $inserted,
            count($vendors),
            $skipped
        ));
    }

    public function down(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$p}rto_vendor_coverage");
    }
}
