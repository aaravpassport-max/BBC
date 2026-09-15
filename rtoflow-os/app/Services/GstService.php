<?php

namespace RTOFLOW\Services;

use RTOFLOW\Config\Env;

if (!defined('ABSPATH')) exit;

/**
 * GST Service — Indian Goods and Services Tax
 *
 * Handles all GST calculations for service fees.
 * Determines CGST+SGST vs IGST based on Place of Supply rules.
 *
 * Rules:
 * - Intra-state supply: CGST (half rate) + SGST (half rate)
 * - Inter-state supply: IGST (full rate)
 * - Place of supply = state of service delivery (city's state)
 *
 * Usage:
 *   $gst = GstService::calculate(1000.00, 'MH', 'MH'); // {cgst:90, sgst:90, igst:0, total:180}
 *   $breakdown = GstService::breakdown($invoice);
 */
class GstService
{
    private float  $gstRate;
    private string $companyState;

    public function __construct()
    {
        $this->gstRate     = Env::float('GST_RATE', 18.0);
        $this->companyState = Env::string('COMPANY_STATE_CODE', '27'); // Maharashtra default
    }

    // ── Calculate GST ─────────────────────────────────────────────────────

    /**
     * Calculate GST components for a taxable amount.
     *
     * @param float  $taxableAmount   Pre-GST amount
     * @param string $supplyStateCode State code of the delivery city (2-digit)
     * @param bool   $inclusive       If true, $taxableAmount already includes GST
     *
     * @return array {taxable, cgst, sgst, igst, total_gst, grand_total, rate, is_interstate}
     */
    public function calculate(float $taxableAmount, string $supplyStateCode = '', bool $inclusive = false): array
    {
        if ($taxableAmount <= 0) {
            return $this->zeroResult($taxableAmount);
        }

        $supplyStateCode = $supplyStateCode ?: $this->companyState;
        $isInterstate    = ($supplyStateCode !== $this->companyState);

        if ($inclusive) {
            // Extract pre-GST amount from GST-inclusive amount
            $taxableAmount = round($taxableAmount / (1 + $this->gstRate / 100), 2);
        }

        $totalGst = round($taxableAmount * ($this->gstRate / 100), 2);

        if ($isInterstate) {
            $cgst = 0.00;
            $sgst = 0.00;
            $igst = $totalGst;
        } else {
            $halfRate = $this->gstRate / 2;
            $cgst     = round($taxableAmount * ($halfRate / 100), 2);
            $sgst     = round($taxableAmount * ($halfRate / 100), 2);
            // Correct for rounding difference
            $igst     = 0.00;
            $totalGst = $cgst + $sgst;
        }

        return [
            'taxable_amount' => round($taxableAmount, 2),
            'cgst'           => $cgst,
            'sgst'           => $sgst,
            'igst'           => $igst,
            'total_gst'      => $totalGst,
            'grand_total'    => round($taxableAmount + $totalGst, 2),
            'gst_rate'       => $this->gstRate,
            'is_interstate'  => $isInterstate,
            'supply_state'   => $supplyStateCode,
            'company_state'  => $this->companyState,
        ];
    }

    /**
     * Calculate GST for a complete service order.
     * Takes a lead and returns full GST breakdown.
     */
    public function calculateForLead(array $lead): array
    {
        global $wpdb;

        // FIX: 'gst_invoicing' flag gate — when disabled, GST is not applied
        // to any lead regardless of the service's gst_applicable setting.
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('gst_invoicing')) {
            return $this->zeroResult((float)$lead['total_amount']);
        }

        // Get the service to check if GST applies
        $service = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}rto_services WHERE id = %d", $lead['service_id']),
            ARRAY_A
        );

        if (!$service || !$service['gst_applicable']) {
            return $this->zeroResult((float)$lead['total_amount']);
        }

        // Get city state code
        $stateCode = $wpdb->get_var($wpdb->prepare(
            "SELECT s.code FROM {$wpdb->prefix}rto_states s
             JOIN {$wpdb->prefix}rto_cities c ON c.state_id = s.id
             WHERE c.id = %d",
            $lead['city_id']
        )) ?: $this->companyState;

        // Government fee is exempt from GST
        $govtFee   = (float)($service['govt_fee'] ?? 0);
        $serviceFee = (float)$lead['total_amount'] - $govtFee;

        if ($serviceFee <= 0) {
            return $this->zeroResult((float)$lead['total_amount']);
        }

        $gst = $this->calculate($serviceFee, $stateCode);

        return array_merge($gst, [
            'govt_fee'    => $govtFee,
            'service_fee' => $serviceFee,
            'invoice_total' => round($serviceFee + $gst['total_gst'] + $govtFee, 2),
        ]);
    }

    // ── GST number validation ─────────────────────────────────────────────

    public static function validateGstin(string $gstin): bool
    {
        $gstin = strtoupper(preg_replace('/\s/', '', $gstin));
        return (bool)preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z]{1}[A-Z\d]{1}[Z]{1}[A-Z\d]{1}$/', $gstin);
    }

    /** Extract state code from GSTIN (first 2 digits) */
    public static function stateFromGstin(string $gstin): string
    {
        return substr(preg_replace('/\D/', '', $gstin), 0, 2);
    }

    // ── SAC code ─────────────────────────────────────────────────────────
    // SAC 999799 — Other services not elsewhere classified (covers most RTO services)
    public static function sacCode(): string
    {
        return '999799';
    }

    // ── HSN/SAC for invoice ───────────────────────────────────────────────
    public static function getServiceDescription(string $serviceName): string
    {
        return "RTO Services — {$serviceName} [SAC: " . self::sacCode() . ']';
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function zeroResult(float $amount): array
    {
        return [
            'taxable_amount' => $amount,
            'cgst'           => 0.00,
            'sgst'           => 0.00,
            'igst'           => 0.00,
            'total_gst'      => 0.00,
            'grand_total'    => $amount,
            'gst_rate'       => 0.0,
            'is_interstate'  => false,
            'supply_state'   => $this->companyState,
            'company_state'  => $this->companyState,
        ];
    }

    /** Format amount in Indian numbering (lakh, crore) */
    public static function formatInr(float $amount): string
    {
        return '₹' . number_format($amount, 2);
    }

    /** Amount in words (for invoice) — simplified */
    public static function amountInWords(float $amount): string
    {
        $rupees = (int)$amount;
        $paise  = (int)round(($amount - $rupees) * 100);

        $inWords = self::intToWords($rupees) . ' Rupees';
        if ($paise > 0) {
            $inWords .= ' and ' . self::intToWords($paise) . ' Paise';
        }
        return $inWords . ' Only';
    }

    private static function intToWords(int $n): string
    {
        if ($n === 0) return 'Zero';
        $ones  = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
                  'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
                  'Seventeen', 'Eighteen', 'Nineteen'];
        $tens  = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $scale = ['', 'Thousand', 'Lakh', 'Crore'];

        $parts = [];
        $chunks = [];

        // Indian numbering: last 3, then pairs of 2
        $chunks[] = $n % 1000;
        $n = (int)($n / 1000);
        while ($n > 0) {
            $chunks[] = $n % 100;
            $n = (int)($n / 100);
        }

        foreach ($chunks as $i => $chunk) {
            if ($chunk === 0) continue;
            $word = '';
            if ($i === 0) {
                // Process 3-digit chunk
                if ($chunk >= 100) {
                    $word .= $ones[(int)($chunk / 100)] . ' Hundred ';
                    $chunk %= 100;
                }
            }
            if ($chunk >= 20) {
                $word .= $tens[(int)($chunk / 10)] . ' ' . $ones[$chunk % 10];
            } else {
                $word .= $ones[$chunk];
            }
            $word  = trim($word);
            $label = $scale[$i] ?? '';
            if ($word) $parts[] = $word . ($label ? ' ' . $label : '');
        }

        return implode(' ', array_reverse($parts));
    }
}
