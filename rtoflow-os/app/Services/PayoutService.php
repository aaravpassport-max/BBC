<?php

namespace RTOFLOW\Services;

use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

/**
 * Payout Service — Vendor Earnings and Disbursement
 *
 * Calculates vendor earnings, applies TDS per Section 194C,
 * and records payout batches.
 */
class PayoutService
{
    private TdsService $tds;

    public function __construct(TdsService $tds)
    {
        $this->tds = $tds;
    }

    // ── Generate payout for a vendor in a period ─────────────────────────

    public function generatePayout(int $vendorId, string $period): array
    {
        global $wpdb;

        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('vendor_payouts')) {
            return ['success' => false, 'message' => 'Vendor payouts are currently disabled.'];
        }

        // Period format: 'YYYY-MM'
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return ['success' => false, 'message' => 'Invalid period format. Use YYYY-MM.'];
        }

        // Check for existing payout for this period
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_vendor_payouts WHERE vendor_id = %d AND period = %s AND status != 'cancelled'",
            $vendorId, $period
        ));
        if ($existing) {
            return ['success' => false, 'message' => "Payout for {$period} already exists."];
        }

        // Get vendor
        $vendor = $wpdb->get_row($wpdb->prepare(
            "SELECT v.*, u.display_name FROM {$wpdb->prefix}rto_vendors v
             JOIN {$wpdb->prefix}users u ON u.ID = v.user_id
             WHERE v.id = %d",
            $vendorId
        ), ARRAY_A);
        if (!$vendor) return ['success' => false, 'message' => 'Vendor not found.'];

        // ENTERPRISE GAP FIX (Phase 1, item 5 — "No penny-drop / bank
        // account verification for vendors"): this is the actual
        // enforcement point — a payout batch can no longer be generated
        // for a vendor whose bank account has not been confirmed real
        // (BankVerificationService::initiate()/handleWebhookEvent()). A
        // typo'd account number or a fraudulent bank-detail change
        // previously had no gate at all before real money could be sent to
        // it; blocking at GENERATION time (not just at the final "mark
        // paid" step) means staff cannot even build a payable batch for an
        // unverified vendor, forcing verification first rather than
        // leaving it as an easy-to-skip later step.
        if (($vendor['bank_verification_status'] ?? 'unverified') !== 'verified') {
            return ['success' => false, 'message' => 'This vendor\'s bank account is not verified yet (status: '
                . ucfirst($vendor['bank_verification_status'] ?? 'unverified')
                . ') — verify it on the vendor\'s profile before generating a payout.'];
        }

        // Get completed leads in this period assigned to vendor
        $leads = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.lead_number, l.total_amount,
                    s.vendor_share, s.name as service_name
             FROM {$wpdb->prefix}rto_leads l
             JOIN {$wpdb->prefix}rto_services s ON s.id = l.service_id
             WHERE l.vendor_id = %d
             AND l.status = 'completed'
             AND DATE_FORMAT(l.completed_at, '%%Y-%%m') = %s
             AND l.payment_status = 'paid'",
            $vendorId, $period
        ), ARRAY_A) ?: [];

        if (empty($leads)) {
            return ['success' => false, 'message' => "No completed paid leads found for vendor in {$period}."];
        }

        // Calculate gross earnings
        $grossAmount = 0.0;
        $leadsData   = [];
        foreach ($leads as $lead) {
            $vendorShare  = (float)$lead['vendor_share'];
            $leadEarning  = round((float)$lead['total_amount'] * ($vendorShare / 100), 2);
            $grossAmount += $leadEarning; // accumulated as float
            $leadsData[]  = [
                'lead_id'      => $lead['id'],
                'lead_number'  => $lead['lead_number'],
                'service_name' => $lead['service_name'],
                'total_amount' => $lead['total_amount'],
                'vendor_share' => $vendorShare,
                'earning'      => $leadEarning,
            ];
        }

        // Apply TDS
        // P5-DB-007 FIX: round accumulated float to 2 decimal places before TDS
        $grossAmount = round($grossAmount, 2);
        $tdsResult = $this->tds->calculate($grossAmount, $vendorId, $period);

        // Save payout record
        // BUGFIX (ghost-success sweep, same class as PaymentService/LeadService
        // fixes this pass): this insert's return value was never checked. If it
        // silently failed, $wpdb->insert_id would still hold whatever value it
        // last held from an unrelated prior insert on the same connection, so
        // $payoutId below would be a bogus, pre-existing row id -- the audit log
        // would then record a "payout.generated" event referencing a payout that
        // was never actually created, and the caller would receive success:true
        // with that same bogus id, with no real payout row behind it anywhere.
        $payoutInserted = $wpdb->insert($wpdb->prefix . 'rto_vendor_payouts', [
            'vendor_id'    => $vendorId,
            'period'       => $period,
            'gross_amount' => $grossAmount,
            'tds_amount'   => $tdsResult['tds'],
            'net_amount'   => $tdsResult['net'],
            'leads_json'   => wp_json_encode($leadsData),
            'notes'        => $tdsResult['reason'],
            'status'       => 'pending',
            'created_at'   => current_time('mysql'),
        ]);

        if (!$payoutInserted) {
            error_log('RTOFLOW PayoutService: payout insert failed for vendor ' . $vendorId . ' period ' . $period . ' — ' . $wpdb->last_error);
            return ['success' => false, 'message' => 'Unable to generate payout. Please try again.'];
        }

        $payoutId = (int)$wpdb->insert_id;

        AuditService::log('payout.generated', null, [
            'payout_id'   => $payoutId,
            'vendor_id'   => $vendorId,
            'period'      => $period,
            'gross'       => $grossAmount,
            'tds'         => $tdsResult['tds'],
            'net'         => $tdsResult['net'],
        ]);

        return [
            'success'      => true,
            'payout_id'    => $payoutId,
            'gross_amount' => $grossAmount,
            'tds_amount'   => $tdsResult['tds'],
            'net_amount'   => $tdsResult['net'],
            'leads_count'  => count($leads),
            'message'      => "Payout generated for {$period}. Net: " . rto_format_inr($tdsResult['net']),
        ];
    }

    // ── Mark payout as processed ──────────────────────────────────────────
    //
    // TRACE: called from Controllers\Admin\PayoutsController::markPaid() (the
    //        only caller — see FIX P0-5) → validates the payout exists and is
    //        not already paid → updates status/utr_number/payment_ref/
    //        payment_method/paid_by/paid_at → decrements the vendor's
    //        pending_balance by net_amount (floored at 0) → writes an
    //        AuditService entry → notifies the vendor by email+SMS.
    //        Preconditions: payoutId refers to an existing, unpaid payout.
    //        Postconditions: rto_vendor_payouts row is 'paid' with a full
    //        payment trail; rto_vendors.pending_balance is reduced exactly
    //        once for this payout; one audit log row; one notification sent.
    //        Edge cases handled: unknown payout id, already-paid payout
    //        (both return success:false and make no writes — this is what
    //        makes the method safe to call more than once for the same id).
    public function markProcessed(int $payoutId, string $utrNumber, string $method = 'bank_transfer', ?int $paidBy = null): array
    {
        global $wpdb;

        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_vendor_payouts WHERE id = %d",
            $payoutId
        ), ARRAY_A);

        if (!$payout) return ['success' => false, 'message' => 'Payout not found.'];
        if ($payout['status'] === 'paid') return ['success' => false, 'message' => 'This payout is already marked as paid.'];

        $utr = Sanitiser::alphanumeric($utrNumber, 50);

        // FIX P0-5: this used to be duplicated inline inside
        // PayoutsController::markPaid() with no audit log and no vendor
        // notification. There is now exactly one place a payout is marked
        // paid, so every payout — regardless of which admin screen or future
        // caller triggers it — gets the same audit trail and the same
        // pending_balance adjustment.
        //
        // BUGFIX (ghost-success sweep, same class as PaymentService/LeadService/
        // PayoutService::generatePayout fixes this pass): neither the status
        // update NOR the pending_balance decrement below had their return
        // values checked, and the two writes ran outside any transaction. If
        // the status update silently failed, the balance was still decremented
        // unconditionally (money removed from the vendor's pending balance for
        // a payout that was never actually marked paid), an audit log entry
        // still recorded "payout.processed", and the vendor was still notified
        // that a payment they never actually received had gone out. Now both
        // writes are wrapped in one transaction and checked; a failure of
        // either rolls back both and returns a real error, with no audit log
        // entry and no vendor notification for a payout that was not genuinely
        // marked paid.
        $wpdb->query('START TRANSACTION');
        try {
            $payoutUpdated = $wpdb->update($wpdb->prefix . 'rto_vendor_payouts', [
                'status'         => 'paid',
                'utr_number'     => $utr,        // canonical schema column
                'payment_ref'    => $utr,        // migration 5 alias, kept for backward compatibility
                'payment_method' => Sanitiser::text($method) ?: 'bank_transfer',
                'paid_by'        => $paidBy ?? get_current_user_id(),
                'paid_at'        => current_time('mysql'),
            ], ['id' => $payoutId]);

            if ($payoutUpdated === false) {
                throw new \RuntimeException('payout status update failed: ' . $wpdb->last_error);
            }

            $balanceUpdated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}rto_vendors SET pending_balance = GREATEST(0, pending_balance - %f) WHERE id = %d",
                (float)$payout['net_amount'], (int)$payout['vendor_id']
            ));

            if ($balanceUpdated === false) {
                throw new \RuntimeException('vendor pending_balance decrement failed: ' . $wpdb->last_error);
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('RTOFLOW PayoutService::markProcessed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to mark payout as paid. Please try again.'];
        }

        AuditService::log('payout.processed', null, [
            'payout_id' => $payoutId,
            'vendor_id' => (int)$payout['vendor_id'],
            'utr'       => $utr,
            'net_amount' => (float)$payout['net_amount'],
        ]);

        // Notify vendor
        $vendorUserId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}rto_vendors WHERE id = %d",
            $payout['vendor_id']
        ));
        if ($vendorUserId) {
            NotificationService::send('payout_processed', [
                'period'       => $payout['period'],
                'gross_amount' => rto_format_inr((float)$payout['gross_amount']),
                'tds_amount'   => rto_format_inr((float)$payout['tds_amount']),
                'net_amount'   => rto_format_inr((float)$payout['net_amount']),
                'utr_number'   => $utr,
            ], $vendorUserId, ['email', 'sms']);
        }

        return ['success' => true, 'message' => 'Payout marked as processed.', 'payout_id' => $payoutId];
    }

    // ── Get vendor earnings summary ────────────────────────────────────────

    public function getEarningsSummary(int $vendorId, int $months = 6): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT period, gross_amount, tds_amount, net_amount, status, paid_at
             FROM {$wpdb->prefix}rto_vendor_payouts
             WHERE vendor_id = %d
             ORDER BY period DESC
             LIMIT %d",
            $vendorId, $months
        ), ARRAY_A) ?: [];
    }
}
