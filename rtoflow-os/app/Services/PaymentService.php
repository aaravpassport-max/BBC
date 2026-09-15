<?php

namespace RTOFLOW\Services;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Security\Encryption;

if (!defined('ABSPATH')) exit;

/**
 * Payment Service
 *
 * Records payments, verifies Razorpay signatures, issues invoices,
 * and triggers post-payment workflow actions.
 *
 * Idempotency: Every payment recording is guarded by a unique key
 * (composed of lead_id + gateway_ref) stored in the DB with a UNIQUE
 * constraint — duplicate webhook calls produce only one payment record.
 */
class PaymentService
{
    private InvoiceService $invoices;

    public function __construct(InvoiceService $invoices)
    {
        $this->invoices = $invoices;
    }

    // ── Record a payment ──────────────────────────────────────────────────

    /**
     * Record a verified payment for a lead.
     * Safe to call multiple times — idempotency key prevents duplicates.
     *
     * @return array ['success', 'payment_id', 'invoice_id', 'message']
     */
    // Extracted purely so this can be verified directly (real-execution
    // harness: verify_payments_duplicate_guard.php) without needing to also
    // fake the transactional insert path, GstService, and InvoiceService
    // just to reach one string-building decision.
    private function buildIdempotencyKey(int $leadId, float $amount, string $method, string $gatewayRef, string $txnId): string
    {
        return $gatewayRef || $txnId
            ? 'lead_' . $leadId . '_ref_' . ($gatewayRef ?: $txnId)
            : 'lead_' . $leadId . '_manual_' . $method . '_' . number_format($amount, 2, '.', '')
                . '_' . date('YmdHi', strtotime(current_time('mysql')));
    }

    public function record(array $data): array
    {
        global $wpdb;

        $leadId     = Sanitiser::int($data['lead_id'] ?? 0, 1);
        $amount     = Sanitiser::amount($data['amount'] ?? 0);
        $method     = Sanitiser::text($data['method'] ?? 'online');
        $txnId      = Sanitiser::text($data['txn_id'] ?? '');
        $gatewayRef = Sanitiser::text($data['gateway_ref'] ?? $txnId);
        $type       = Sanitiser::text($data['type'] ?? 'full');

        // Idempotency key: prevents duplicate payment records from retried webhooks.
        //
        // Known Limitations audit fix: when neither a gateway reference nor a
        // txn_id exists — always true for a manually-recorded (e.g. cash)
        // payment — this previously fell back to Encryption::token(8), a
        // fresh random value on every single call. That made the key
        // useless as a dedup guard for manual payments specifically: two
        // accidental clicks of "Record Payment" for the same cash amount
        // each got their own unique random key and both inserted cleanly,
        // creating an undetected duplicate ledger row. Online/webhook
        // payments were never affected — the gateway's own real reference is
        // still used first and is unchanged here.
        //
        // Fixed with a short-window dedup key instead: lead + amount + method
        // + the current minute. A genuine second cash payment for the same
        // amount an hour later still gets its own distinct key (correct —
        // that is a legitimate second payment, not a duplicate click); two
        // clicks within the same minute collide and the second is treated as
        // the same payment, exactly like the existing webhook-retry case.
        $idempKey = $this->buildIdempotencyKey($leadId, $amount, $method, $gatewayRef, $txnId);

        // Check for duplicate
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_payments WHERE idempotency_key = %s",
            $idempKey
        ));
        if ($existing) {
            return ['success' => true, 'payment_id' => (int)$existing, 'message' => 'Payment already recorded (idempotent).', 'duplicate' => true];
        }

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_leads WHERE id = %d AND deleted_at IS NULL",
            $leadId
        ), ARRAY_A);

        if (!$lead) return ['success' => false, 'message' => 'Lead not found.'];

        if ($amount <= 0) return ['success' => false, 'message' => 'Payment amount must be greater than zero.'];

        // Calculate GST amount for this payment
        $gstService  = new GstService();
        $gstBreakdown = $gstService->calculateForLead($lead);
        $gstAmount   = round($amount * ($gstBreakdown['total_gst'] / max(0.01, $gstBreakdown['grand_total'])), 2);

        // P5-DB-002 FIX: wrap multi-step payment in a database transaction
        $wpdb->query('START TRANSACTION');
        try {
            // Insert payment record
            $inserted = $wpdb->insert($wpdb->prefix . 'rto_payments', [
                'lead_id'         => $leadId,
                'txn_id'          => $txnId,
                'amount'          => $amount,
                'gst_amount'      => $gstAmount,
                'type'            => $type,
                'method'          => $method,
                'gateway_ref'     => $gatewayRef,
                'idempotency_key' => $idempKey,
                'status'          => 'completed',
                'notes'           => Sanitiser::text($data['notes'] ?? ''),
                'created_by'      => get_current_user_id(),
                'created_at'      => current_time('mysql'),
            ]);

            if (!$inserted) {
                $wpdb->query('ROLLBACK');
                // P2-SEC-008 FIX: never expose DB error to client
                error_log('RTOFLOW PaymentService: Insert failed — ' . $wpdb->last_error);
                return ['success' => false, 'message' => 'Unable to record payment. Please try again.'];
            }

            $paymentId = (int)$wpdb->insert_id;

            // Update lead payment totals — lock rows to prevent race condition
            $paidTotal = (float)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}rto_payments
                 WHERE lead_id = %d AND status = 'completed' FOR UPDATE",
                $leadId
            ));

            $paymentStatus = $paidTotal >= (float)$lead['total_amount'] ? 'paid' : 'partial';
            // BUGFIX (found via a systematic write-return-value sweep for the
            // "ghost success" failure class): this update's return value was
            // never checked, unlike the rto_payments insert immediately above
            // it in this same transaction. If it silently failed (a lock
            // timeout, deadlock, or any other DB-level error that doesn't
            // throw), $wpdb->query('COMMIT') would still run and persist the
            // new payment row -- so the money would be recorded as paid, but
            // the order's own paid_amount/payment_status/status would not
            // reflect it at all, desyncing the two in a way nothing else in
            // the app would ever detect or correct. Now checked and rolled
            // back like every other step in this transaction.
            $leadUpdated = $wpdb->update($wpdb->prefix . 'rto_leads', [
                'paid_amount'    => $paidTotal,
                'payment_status' => $paymentStatus,
                'status'         => $lead['status'] === 'created' || $lead['status'] === 'payment_pending'
                    ? 'payment_received' : $lead['status'],
                'updated_at'     => current_time('mysql'),
            ], ['id' => $leadId]);

            if ($leadUpdated === false) {
                $wpdb->query('ROLLBACK');
                error_log('RTOFLOW PaymentService: lead payment-status update failed — ' . $wpdb->last_error);
                return ['success' => false, 'message' => 'Unable to record payment. Please try again.'];
            }

            $wpdb->query('COMMIT');

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('RTOFLOW PaymentService: Transaction failed — ' . $e->getMessage());
            return ['success' => false, 'message' => 'Payment could not be processed. Please try again.'];
        }

        AuditService::logPayment($leadId, $amount, $method, $txnId);

        // Generate invoice OUTSIDE the transaction (file I/O must not block rollback)
        $invoiceResult = ['success' => false, 'invoice_id' => null];
        if ($paymentStatus === 'paid') {
            try {
                $invoiceResult = $this->invoices->generate($leadId, $paymentId);
            } catch (\Throwable $e) {
                error_log('RTOFLOW PaymentService: Invoice generation failed: ' . $e->getMessage());
            }
        }

        // Trigger post-payment hooks
        do_action('rtoflow_payment_recorded', $leadId, $paymentId, $amount, $lead);
        if ($paymentStatus === 'paid') {
            do_action('rtoflow_payment_completed', $leadId, $paymentId, $invoiceResult['invoice_id'] ?? null);
        }

        return [
            'success'    => true,
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceResult['invoice_id'] ?? null,
            'message'    => 'Payment recorded successfully.',
        ];
    }

    // ── Razorpay webhook verification ─────────────────────────────────────

    /**
     * Verify a Razorpay webhook signature.
     * Call this BEFORE recording any payment from a webhook.
     */
    public function verifyRazorpayWebhook(string $payload, string $signature): bool
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('webhook_razorpay')) {
            return false;
        }
        // FIX (10-workstream integration pass): this read ONLY from .env,
        // the exact same class of bug already fixed once for the Razorpay
        // API key/secret (integrations/Razorpay.php's getKey()/getSecret()) —
        // the admin Settings screen had no way to make this take effect at
        // all. Now checks the encrypted wp_options value first (same pattern
        // Razorpay::getSecret() uses), falling back to .env for installs that
        // configure it there instead.
        $secret = \RTOFLOW\Security\Encryption::decryptSafe(get_option('rtoflow_razorpay_webhook_secret_enc', ''))
            ?: \RTOFLOW\Config\Env::string('RAZORPAY_WEBHOOK_SECRET', '');
        if (!$secret) {
            error_log('RTOFLOW PaymentService: Razorpay webhook secret not configured (checked Settings and .env).');
            return false;
        }
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Verify a Razorpay payment signature (from checkout callback).
     */
    public function verifyRazorpaySignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret   = \RTOFLOW\Config\Env::string('RAZORPAY_SECRET', '');
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
        return hash_equals($expected, $signature);
    }

    // ── Refund ────────────────────────────────────────────────────────────

    public function initiateRefund(int $paymentId, float $amount, string $reason, int $initiatedBy): array
    {
        global $wpdb;

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_payments WHERE id = %d",
            $paymentId
        ), ARRAY_A);

        if (!$payment) return ['success' => false, 'message' => 'Payment not found.'];
        if ($amount > (float)$payment['amount']) return ['success' => false, 'message' => 'Refund amount exceeds payment amount.'];

        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase — this one is financially significant):
        // the insert()'s result was discarded, and the caller was ALWAYS
        // told "Refund initiated and pending admin approval." even if the
        // row never made it into rto_refunds — leaving a customer expecting
        // a refund that no admin queue would ever show, with no record it
        // was ever requested.
        $inserted = $wpdb->insert($wpdb->prefix . 'rto_refunds', [
            'payment_id'  => $paymentId,
            'lead_id'     => $payment['lead_id'],
            'amount'      => $amount,
            'reason'      => Sanitiser::text($reason, 500),
            'status'      => 'pending',
            'created_by'  => $initiatedBy,
            'created_at'  => current_time('mysql'),
        ]);
        if (!$inserted) {
            error_log("RTOFLOW PaymentService::initiateRefund(): refund insert failed for payment {$paymentId} — " . $wpdb->last_error);
            return ['success' => false, 'message' => 'Could not record the refund request. Please try again.'];
        }

        AuditService::log('payment.refund_initiated', (int)$payment['lead_id'], [
            'payment_id' => $paymentId,
            'amount'     => $amount,
            'reason'     => $reason,
        ]);

        // FIX (Phase 1, item 2 — client request approval needs this): the
        // caller previously had no way to know WHICH refund row was just
        // created (only that one was). Router::approveClientRequest() needs
        // the new id to link rto_client_requests.refund_id back to it.
        return ['success' => true, 'message' => 'Refund initiated and pending admin approval.', 'refund_id' => (int)$wpdb->insert_id];
    }

    // ── Summary ───────────────────────────────────────────────────────────

    public function getPaymentsForLead(int $leadId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_payments WHERE lead_id = %d ORDER BY created_at DESC",
            $leadId
        ), ARRAY_A) ?: [];
    }
}
