<?php

namespace RTOFLOW\Services;

use RTOFLOW\Config\Config;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (gap-analysis Section 7 — "Security/reliability/
 * failure-management gaps: no payments reconciliation job").
 *
 * ROOT CAUSE: Router::routeWebhook()'s 'razorpay' branch is the ONLY path
 * that ever writes a captured payment into rto_payments — it fires when
 * Razorpay's webhook successfully reaches this server and its signature
 * verifies. If that webhook is ever lost (this server briefly down during
 * the callback, a network blip between Razorpay and this server, the
 * webhook URL misconfigured after a domain change, the signature check
 * failing due to a rotated secret not yet updated here), the customer's
 * card/UPI is charged and Razorpay's own records show it as captured, but
 * rto_payments never gets a row — the lead looks unpaid forever with no
 * automatic way to notice the mismatch, only a support ticket from a
 * confused customer who was charged for a service that shows "payment
 * pending". There was no process that ever cross-checked RTOFLOW's local
 * payment records against Razorpay's own source of truth.
 *
 * This service closes that gap the same way real payment-ops teams do:
 * periodically pull the gateway's own payment list for a lookback window
 * and diff it against local records. It does not replace the webhook path
 * (that stays the fast, real-time path for the 99%+ case) — it is the
 * safety net for the webhook-lost case, running on a schedule via
 * runDaily() below.
 */
class PaymentReconciliationService
{
    /** How far back to re-check on every run — wide enough to catch a
     *  webhook that was lost hours ago, narrow enough to keep the API call
     *  and local query both cheap on every run. */
    private const LOOKBACK_HOURS = 48;

    private \wpdb $db;
    private string $p;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->p  = $wpdb->prefix;
    }

    // TRACE: fired by the daily rtoflow_payment_reconciliation cron →
    //        fetches Razorpay's own payment list for the last 48h
    //        (GET /v1/payments, Basic-Auth with key:secret) →
    //        for each remote 'captured' payment carrying a lead_id note,
    //        checks whether rto_payments already has a row for that
    //        gateway payment id (by txn_id) →
    //        if missing: this is exactly the "webhook was lost" case —
    //        records the payment via the SAME PaymentService::record() path
    //        the webhook itself would have used, so it goes through
    //        identical validation/GST/invoice/notification logic →
    //        also audit-logs every discrepancy found, whether or not it
    //        could be auto-recovered →
    //        preconditions: razorpay_key/secret configured; webhook_razorpay
    //        feature flag enabled (same gate the live webhook itself uses —
    //        no point reconciling against a gateway integration that is
    //        deliberately turned off) →
    //        postconditions: any payment captured at Razorpay but missing
    //        locally is now recorded; every discrepancy is audit-logged →
    //        edge cases: Razorpay API unreachable/rate-limited → caught,
    //        logged, no partial state (nothing was recorded from a request
    //        that never completed); a captured-at-Razorpay payment with no
    //        lead_id note (created outside RTOFLOW's own checkout flow) →
    //        skipped, nothing to reconcile it against
    public function runDaily(): void
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('webhook_razorpay')) {
            return;
        }

        $key    = Config::get('razorpay_key');
        $secret = Config::get('razorpay_secret');
        if (!$key || !$secret) {
            error_log('RTOFLOW PaymentReconciliationService: razorpay_key/secret not configured — skipping run.');
            return;
        }

        $from = time() - (self::LOOKBACK_HOURS * 3600);
        $response = wp_remote_get('https://api.razorpay.com/v1/payments?from=' . $from . '&count=100', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Basic ' . base64_encode($key . ':' . $secret)],
        ]);

        if (is_wp_error($response)) {
            error_log('RTOFLOW PaymentReconciliationService: Razorpay API call failed — ' . $response->get_error_message());
            AuditService::log('payment.reconciliation_failed', null, ['error' => $response->get_error_message()]);
            return;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('RTOFLOW PaymentReconciliationService: Razorpay API returned HTTP ' . $code);
            AuditService::log('payment.reconciliation_failed', null, ['http_code' => $code]);
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $remotePayments = $data['items'] ?? [];

        $recovered = 0;
        $skipped   = 0;

        foreach ($remotePayments as $payment) {
            if (($payment['status'] ?? '') !== 'captured') continue;

            $leadId = (int)($payment['notes']['lead_id'] ?? 0);
            if (!$leadId) { $skipped++; continue; }

            $txnId = $payment['id'] ?? '';
            if (!$txnId) continue;

            $existing = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->p}rto_payments WHERE txn_id=%s", $txnId
            ));
            if ($existing) continue; // already recorded — the normal, expected case

            // Discrepancy found: Razorpay shows this as captured money, but
            // no local record exists. Recover it via the exact same path
            // the webhook itself uses.
            AuditService::log('payment.reconciliation_discrepancy_found', $leadId, [
                'txn_id' => $txnId, 'amount' => (float)($payment['amount'] ?? 0) / 100,
            ]);

            try {
                $leadExists = (int)$this->db->get_var($this->db->prepare(
                    "SELECT COUNT(*) FROM {$this->p}rto_leads WHERE id=%d", $leadId
                ));
                if (!$leadExists) { $skipped++; continue; }

                $svc = new PaymentService(new InvoiceService(new GstService()));
                $result = $svc->record([
                    'lead_id'     => $leadId,
                    'amount'      => (float)($payment['amount'] ?? 0) / 100,
                    'method'      => $payment['method'] ?? 'razorpay',
                    'txn_id'      => $txnId,
                    'gateway_ref' => $payment['order_id'] ?? '',
                    'type'        => 'full',
                    'notes'       => 'Recovered by payment reconciliation job — original webhook was never received.',
                ]);
                if ($result['success'] ?? false) {
                    $recovered++;
                    AuditService::log('payment.reconciliation_recovered', $leadId, ['txn_id' => $txnId]);
                }
            } catch (\Throwable $e) {
                // One bad record must not stop reconciling the rest of the batch.
                error_log('RTOFLOW PaymentReconciliationService: recovery failed for txn_id=' . $txnId . ' — ' . $e->getMessage());
            }
        }

        AuditService::log('payment.reconciliation_completed', null, [
            'checked' => count($remotePayments), 'recovered' => $recovered, 'skipped_no_lead' => $skipped,
        ]);
    }
}
