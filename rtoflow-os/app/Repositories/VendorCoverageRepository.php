<?php
/**
 * VendorCoverageRepository
 *
 * Reads/writes the normalized `rto_vendor_coverage` join table introduced by
 * migration 2024_01_01_000010_create_vendor_coverage.php as an indexed
 * alternative to the JSON_CONTAINS-based lookup in
 * VendorRepository::get_eligible().
 *
 * This class is additive and NOT yet wired into any call site.
 * VendorRepository::get_eligible() (the method VendorService::auto_assign()
 * currently calls) is left exactly as-is. See the "FUTURE INTEGRATION" note
 * below and the task's final report for what a later change would need to
 * do to cut over.
 *
 * get_eligible_by_coverage() returns the exact same row shape as
 * VendorRepository::get_eligible() — id, rating, completion_rate,
 * acceptance_rate, total_jobs — so it is a drop-in replacement candidate for
 * that method's SELECT once the coverage table is kept in sync with writes.
 *
 * sync_vendor_coverage() is the write-side counterpart: given a vendor's
 * current cities[]/services[] (the same arrays stored in rto_vendors.cities/
 * services), it replaces that vendor's rows in rto_vendor_coverage with the
 * cross-product of the two arrays. Nothing in this codebase calls it yet —
 * wiring it into VendorService::create()/update() (and VendorsController's
 * admin store()/update(), which write cities/services directly) is left for
 * a separate integration pass so both systems can be kept in sync
 * deliberately rather than as a side effect of adding this file.
 */

namespace RTOFLOW\Repositories;

use RTOFLOW\Config\MatchingConfig;

if (!defined('ABSPATH')) exit;

class VendorCoverageRepository
{
    /**
     * Indexed-join equivalent of VendorRepository::get_eligible(). Same
     * WHERE conditions (status='active', kyc_status='verified', rating >=
     * configured minimum), same ORDER BY / LIMIT, same result shape — the
     * only difference is that vendor coverage is matched via an indexed
     * join on rto_vendor_coverage instead of JSON_CONTAINS on the JSON
     * columns.
     *
     * Requires rto_vendor_coverage to be populated and kept current for a
     * vendor (see sync_vendor_coverage()) — a vendor whose coverage rows
     * are stale or missing will not appear here even if its cities/services
     * JSON columns say otherwise.
     *
     * @return array<int, array{id:int,rating:float,completion_rate:float,acceptance_rate:float,total_jobs:int}>
     */
    public function get_eligible_by_coverage(int $cityId, int $serviceId): array
    {
        global $wpdb;

        $minRating = MatchingConfig::get('min_rating');
        $poolSize  = (int) MatchingConfig::get('candidate_pool_size');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.rating, v.completion_rate, v.acceptance_rate, v.total_jobs
             FROM {$wpdb->prefix}rto_vendor_coverage cov
             JOIN {$wpdb->prefix}rto_vendors v ON v.id = cov.vendor_id
             WHERE cov.city_id = %d AND cov.service_id = %d
               AND v.status = 'active' AND v.kyc_status = 'verified' AND v.rating >= %f
             ORDER BY v.rating DESC, v.completion_rate DESC
             LIMIT %d",
            $cityId,
            $serviceId,
            $minRating,
            $poolSize
        ), ARRAY_A) ?: [];
    }

    /**
     * Replace a vendor's coverage rows with the cross-product of the given
     * city IDs and service IDs. Delete-then-insert (not a diff) so this is
     * safe to call unconditionally whenever a vendor's cities/services JSON
     * is written, matching the "we just wrote the whole array" shape those
     * call sites already use.
     *
     * Not currently called from anywhere — see class docblock.
     *
     * @param int[] $cities
     * @param int[] $services
     */
    public function sync_vendor_coverage(int $vendorId, array $cities, array $services): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->delete($p . 'rto_vendor_coverage', ['vendor_id' => $vendorId]);

        $cityIds    = array_values(array_unique(array_filter(array_map('intval', $cities), static fn($id) => $id > 0)));
        $serviceIds = array_values(array_unique(array_filter(array_map('intval', $services), static fn($id) => $id > 0)));

        if (!$cityIds || !$serviceIds) {
            return;
        }

        $rows = [];
        foreach ($cityIds as $cityId) {
            foreach ($serviceIds as $serviceId) {
                $rows[] = $wpdb->prepare('(%d,%d,%d)', $vendorId, $cityId, $serviceId);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each
            // tuple was already produced via $wpdb->prepare() above.
            $wpdb->query(
                "INSERT IGNORE INTO {$p}rto_vendor_coverage (vendor_id, city_id, service_id) VALUES "
                . implode(',', $chunk)
            );
        }
    }
}
