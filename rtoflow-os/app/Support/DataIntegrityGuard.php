<?php

namespace RTOFLOW\Support;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 8, item — "CHECK constraints are silently
 * skipped on older MySQL/MariaDB"):
 *
 * database/migrations/2024_01_01_000002_add_performance_indexes.php only
 * adds its CHECK constraints when version_compare($version, '8.0.16', '>=')
 * — MySQL first supported CHECK enforcement in 8.0.16, and MariaDB (any
 * version, including the versions most common managed hosts still run)
 * parses but never enforces plain CHECK syntax the way that migration
 * writes it. On any of those hosts the five constraints below
 * (chk_rating, chk_gst_rate, chk_score, chk_priority, chk_amounts) are
 * silently absent, and — before this fix — nothing in the application
 * layer stood in for them, so a bug or a hand-edited SQL import could
 * write rating=97 or gst_rate=999 straight into the table.
 *
 * This class is the application-level fallback: QueryBuilder::insert()
 * and ::update() call self::check() on every write, unconditionally,
 * regardless of which DB engine/version is actually enforcing (or not
 * enforcing) the real CHECK constraint — so the invariant holds even on
 * a host where the DB-level constraint never got applied. Only fields
 * actually present in the write are checked, so partial updates that
 * don't touch a constrained column are unaffected.
 */
final class DataIntegrityGuard
{
    /**
     * Table name (without the wpdb prefix) => field => validator closure.
     * Mirrors the CHECK expressions in
     * 2024_01_01_000002_add_performance_indexes.php exactly.
     */
    private const RULES = [
        'rto_vendors' => [
            'rating' => ['chk_rating', 'rating BETWEEN 0.00 AND 5.00'],
        ],
        'rto_invoices' => [
            'gst_rate' => ['chk_gst_rate', 'gst_rate IN (0, 5, 12, 18, 28)'],
        ],
        'rto_ratings' => [
            'score' => ['chk_score', 'score BETWEEN 1 AND 5'],
        ],
        'rto_leads' => [
            'priority' => ['chk_priority', 'priority BETWEEN 1 AND 5'],
        ],
        'rto_vendor_payouts' => [
            'tds_amount'   => ['chk_amounts', 'tds_amount >= 0'],
            'net_amount'   => ['chk_amounts', 'net_amount >= 0'],
            'gross_amount' => ['chk_amounts', 'gross_amount >= 0'],
        ],
    ];

    /**
     * @throws \InvalidArgumentException if a constrained field is present
     *         in $data and out of range — mirrors what the DB-level CHECK
     *         would have rejected with an SQL error.
     */
    public static function check(string $tableWithPrefix, array $data, string $prefix): void
    {
        $table = str_starts_with($tableWithPrefix, $prefix)
            ? substr($tableWithPrefix, strlen($prefix))
            : $tableWithPrefix;

        // Strip a trailing alias fragment some callers append (e.g. "rto_leads l").
        $table = trim(explode(' ', $table)[0]);

        if (!isset(self::RULES[$table])) return;

        foreach (self::RULES[$table] as $field => [$name, $expr]) {
            if (!array_key_exists($field, $data)) continue;
            $val = $data[$field];
            if ($val === null) continue;

            $ok = match ($table . '.' . $field) {
                'rto_vendors.rating'          => is_numeric($val) && $val >= 0.00 && $val <= 5.00,
                'rto_invoices.gst_rate'       => in_array((float)$val, [0, 5, 12, 18, 28], true),
                'rto_ratings.score'           => is_numeric($val) && $val >= 1 && $val <= 5,
                'rto_leads.priority'          => is_numeric($val) && $val >= 1 && $val <= 5,
                'rto_vendor_payouts.tds_amount',
                'rto_vendor_payouts.net_amount',
                'rto_vendor_payouts.gross_amount' => is_numeric($val) && $val >= 0,
                default => true,
            };

            if (!$ok) {
                throw new \InvalidArgumentException(sprintf(
                    'DataIntegrityGuard: %s.%s = %s violates %s (%s)',
                    $table, $field, var_export($val, true), $name, $expr
                ));
            }
        }
    }
}
