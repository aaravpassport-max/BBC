<?php

namespace RTOFLOW\Services;

use RTOFLOW\Config\Env;

if (!defined('ABSPATH')) exit;

/**
 * TDS Service — Tax Deducted at Source on Vendor Payouts
 *
 * Section 194C: TDS on payments to contractors (vendors)
 * - Rate: 1% for individuals/HUF, 2% for companies
 * - Threshold: ₹30,000 per single payment OR ₹1,00,000 aggregate in a FY
 * - Lower deduction: If vendor furnishes Form 15G/15H or lower deduction certificate
 *
 * Section 194H: TDS on commission/brokerage
 * - Rate: 5% when platform fee is structured as commission
 *
 * For RTOFLOW, Section 194C (1%) applies by default for individual vendors.
 *
 * Usage:
 *   $tds = TdsService::calculate(25000, $vendorId);
 *   // {gross: 25000, tds: 250, net: 24750, rate: 1, applicable: true}
 */
class TdsService
{
    private float $tdsRate;
    private float $singleThreshold;
    private float $aggregateThreshold;

    public function __construct()
    {
        $this->tdsRate            = Env::float('TDS_RATE', 1.0);
        $this->singleThreshold    = Env::float('TDS_THRESHOLD', 30000.0);
        $this->aggregateThreshold = 100000.0; // ₹1 lakh FY aggregate
    }

    // ── Calculate TDS ─────────────────────────────────────────────────────

    /**
     * Calculate TDS for a vendor payout.
     *
     * @param float $grossAmount   Total payout amount (before TDS)
     * @param int   $vendorId      Vendor ID (to check FY aggregate)
     * @param string $period       Period string 'YYYY-MM' for FY aggregate lookup
     *
     * @return array {gross, tds, net, rate, applicable, reason}
     */
    public function calculate(float $grossAmount, int $vendorId = 0, string $period = ''): array
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('tds_deduction')) {
            return $this->result($grossAmount, 0.0, 0.0, false, 'TDS deduction disabled');
        }

        if ($grossAmount <= 0) {
            return $this->result($grossAmount, 0.0, 0.0, false, 'Zero amount');
        }

        // Check if vendor has lower deduction certificate
        if ($vendorId && $this->hasLowerDeductionCert($vendorId)) {
            return $this->result($grossAmount, 0.0, 0.0, false, 'Lower deduction certificate on file');
        }

        // Single payment threshold check
        if ($grossAmount < $this->singleThreshold) {
            // Check aggregate for financial year
            if ($vendorId) {
                $fyAggregate = $this->getFyAggregate($vendorId, $period);
                if (($fyAggregate + $grossAmount) < $this->aggregateThreshold) {
                    return $this->result($grossAmount, 0.0, 0.0, false,
                        sprintf('Below threshold (FY aggregate ₹%.0f + ₹%.0f = ₹%.0f < ₹%.0f)',
                            $fyAggregate, $grossAmount,
                            $fyAggregate + $grossAmount,
                            $this->aggregateThreshold
                        )
                    );
                }
            } else {
                return $this->result($grossAmount, 0.0, 0.0, false,
                    "Below single-payment threshold of ₹" . number_format($this->singleThreshold)
                );
            }
        }

        // Apply TDS
        $tdsAmount = round($grossAmount * ($this->tdsRate / 100), 2);
        $netAmount = round($grossAmount - $tdsAmount, 2);

        return $this->result($grossAmount, $tdsAmount, $netAmount, true,
            "Section 194C — {$this->tdsRate}% on ₹" . number_format($grossAmount, 2)
        );
    }

    // ── Batch calculate for payout run ────────────────────────────────────

    /**
     * Calculate TDS for multiple vendor payouts in one period.
     * Returns array keyed by vendor_id.
     */
    public function calculateBatch(array $payouts, string $period = ''): array
    {
        $results = [];
        foreach ($payouts as $vendorId => $grossAmount) {
            $results[$vendorId] = $this->calculate((float)$grossAmount, (int)$vendorId, $period);
        }
        return $results;
    }

    // ── TDS Certificate (Form 16A) ────────────────────────────────────────

    /**
     * Get TDS summary for Form 16A generation.
     * Returns all TDS deductions for a vendor in a quarter.
     */
    public function getQuarterSummary(int $vendorId, string $quarter): array
    {
        // Quarter format: 'Q1-2024' (April–June 2024)
        global $wpdb;

        $periods = $this->quarterToPeriods($quarter);
        if (empty($periods)) return [];

        $placeholders = implode(', ', array_fill(0, count($periods), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT period, gross_amount, tds_amount, net_amount, status, paid_at
             FROM {$wpdb->prefix}rto_vendor_payouts
             WHERE vendor_id = %d AND period IN ({$placeholders}) AND tds_amount > 0
             ORDER BY period",
            array_merge([$vendorId], $periods)
        ), ARRAY_A) ?: [];

        $totalGross = array_sum(array_column($rows, 'gross_amount'));
        $totalTds   = array_sum(array_column($rows, 'tds_amount'));
        $totalNet   = array_sum(array_column($rows, 'net_amount'));

        return [
            'vendor_id'   => $vendorId,
            'quarter'     => $quarter,
            'periods'     => $rows,
            'total_gross' => $totalGross,
            'total_tds'   => $totalTds,
            'total_net'   => $totalNet,
            'tds_rate'    => $this->tdsRate,
        ];
    }

    // ── Financial Year helpers ────────────────────────────────────────────

    public static function currentFy(): string
    {
        $month = (int)date('n');
        $year  = (int)date('Y');
        return $month >= 4 ? "FY{$year}-" . ($year + 1) : "FY" . ($year - 1) . "-{$year}";
    }

    public static function fyPeriods(string $fy = ''): array
    {
        if (!$fy) $fy = self::currentFy();
        preg_match('/FY(\d{4})-(\d{4})/', $fy, $m);
        if (!$m) return [];

        $startYear = (int)$m[1];
        $endYear   = (int)$m[2];
        $periods   = [];

        // April of start year → March of end year
        for ($m = 4; $m <= 12; $m++) {
            $periods[] = $startYear . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
        }
        for ($m = 1; $m <= 3; $m++) {
            $periods[] = $endYear . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
        }

        return $periods;
    }

    // ── Internal ──────────────────────────────────────────────────────────

    private function getFyAggregate(int $vendorId, string $currentPeriod): float
    {
        global $wpdb;
        $fyPeriods = self::fyPeriods();
        if (empty($fyPeriods)) return 0.0;

        // Exclude the current period from aggregate (it hasn't been saved yet)
        $periods = array_filter($fyPeriods, fn($p) => $p !== $currentPeriod);
        if (empty($periods)) return 0.0;

        $placeholders = implode(', ', array_fill(0, count($periods), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $aggregate = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(gross_amount), 0)
             FROM {$wpdb->prefix}rto_vendor_payouts
             WHERE vendor_id = %d AND period IN ({$placeholders})",
            array_merge([$vendorId], array_values($periods))
        ));

        return (float)$aggregate;
    }

    private function hasLowerDeductionCert(int $vendorId): bool
    {
        global $wpdb;

        return (bool)get_user_meta(
            (int)($wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM " . $wpdb->prefix . "rto_vendors WHERE id = %d",
                $vendorId
            )) ?: 0),
            'rtoflow_tds_lower_cert',
            true
        );
    }

    private function quarterToPeriods(string $quarter): array
    {
        // Q1-2024 → April, May, June 2024
        preg_match('/Q([1-4])-(\d{4})/', $quarter, $m);
        if (!$m) return [];

        $q    = (int)$m[1];
        $year = (int)$m[2];

        // Indian FY quarters
        $monthMap = [1 => [4,5,6], 2 => [7,8,9], 3 => [10,11,12], 4 => [1,2,3]];
        $months   = $monthMap[$q];
        $actualYear = $q === 4 ? $year + 1 : $year;

        return array_map(
            fn($m) => $actualYear . '-' . str_pad($m, 2, '0', STR_PAD_LEFT),
            $months
        );
    }

    private function result(float $gross, float $tds, float $net, bool $applicable, string $reason): array
    {
        return [
            'gross'      => $gross,
            'tds'        => $tds,
            'net'        => $net > 0 ? $net : $gross,
            'rate'       => $this->tdsRate,
            'applicable' => $applicable,
            'reason'     => $reason,
        ];
    }
}

/** Helper for internal DB access without full DI */
