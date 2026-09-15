<?php

namespace RTOFLOW\Services;

use RTOFLOW\Config\Config;
use RTOFLOW\Security\Encryption;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 1, item 5 — "No penny-drop / bank account
 * verification for vendors"): vendor bank account + IFSC are accepted and
 * encrypted (VendorsController::store()) but were never validated against a
 * real account before payouts could be sent to it — a typo or fraud risk
 * sent real money to the wrong account with no verification gate at all.
 *
 * Integrates Razorpay's real Fund Account Validation product (the standard
 * "penny-drop" mechanism — Razorpay's own docs: "validate a bank account or
 * VPA by attempting a small transaction and confirming the beneficiary
 * name"), reusing the exact same razorpay_key/razorpay_secret Settings
 * already power live payments and PaymentReconciliationService, and the
 * same Basic-Auth + wp_remote_* call pattern established there.
 *
 * This is genuinely a THREE-call chain (Razorpay's own API shape, not a
 * simplification made here): Contact → Fund Account → Validation. The
 * validation's real outcome arrives ASYNCHRONOUSLY via Razorpay's webhook
 * (fund_account.validation.completed/.failed) — see
 * Router::routeWebhook()'s 'razorpay' branch, extended to call
 * handleWebhookEvent() below for these two event types. A vendor's
 * bank_verification_status is therefore genuinely 'pending' between
 * initiate() succeeding and the webhook arriving, not an error state.
 */
class BankVerificationService
{
    private \wpdb $db;
    private string $p;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->p  = $wpdb->prefix;
    }

    /**
     * Starts (or restarts) verification for one vendor's currently-stored
     * bank details. Safe to call again for a vendor already 'pending' or
     * 'failed' — a fresh Contact/Fund Account/Validation chain is created
     * each time rather than trying to reuse a possibly-stale prior one.
     *
     * @return array{success:bool,message:string}
     */
    public function initiate(int $vendorId): array
    {
        $key    = Config::get('razorpay_key');
        $secret = Config::get('razorpay_secret');
        if (!$key || !$secret) {
            return ['success' => false, 'message' => 'Bank verification is not configured — add the Razorpay API keys under Settings → Payments first.'];
        }

        $vendor = $this->db->get_row($this->db->prepare(
            "SELECT id, full_name, mobile, email, bank_details_enc FROM {$this->p}rto_vendors WHERE id=%d",
            $vendorId
        ), ARRAY_A);
        if (!$vendor) return ['success' => false, 'message' => 'Vendor not found.'];
        if (empty($vendor['bank_details_enc'])) return ['success' => false, 'message' => 'This vendor has no bank details on file to verify.'];

        $bank = json_decode(Encryption::decryptSafe($vendor['bank_details_enc']), true) ?: [];
        $accountNumber = (string)($bank['bank_account'] ?? '');
        $ifsc          = strtoupper((string)($bank['bank_ifsc'] ?? ''));
        if ($accountNumber === '' || $ifsc === '') {
            return ['success' => false, 'message' => 'Bank account number or IFSC is missing/unreadable for this vendor.'];
        }

        $auth = ['Authorization' => 'Basic ' . base64_encode($key . ':' . $secret), 'Content-Type' => 'application/json'];

        // Step 1: Contact — Razorpay requires every fund account to belong
        // to a contact record. type=vendor matches how Razorpay itself
        // categorises this relationship (we are paying a vendor, not a
        // customer or employee).
        $contactResp = wp_remote_post('https://api.razorpay.com/v1/contacts', [
            'timeout' => 15, 'headers' => $auth,
            'body' => wp_json_encode([
                'name'    => $vendor['full_name'],
                'email'   => $vendor['email'] ?: null,
                'contact' => $vendor['mobile'] ?: null,
                'type'    => 'vendor',
                'reference_id' => 'rtoflow_vendor_' . $vendorId,
            ]),
        ]);
        $contact = $this->decodeOrFail($contactResp, 'creating the contact');
        if (!$contact['success']) return $contact;
        $contactId = $contact['data']['id'] ?? null;
        if (!$contactId) return ['success' => false, 'message' => 'Razorpay did not return a contact id.'];

        // Step 2: Fund Account — the actual bank account details, tied to
        // the contact just created.
        $fundResp = wp_remote_post('https://api.razorpay.com/v1/fund_accounts', [
            'timeout' => 15, 'headers' => $auth,
            'body' => wp_json_encode([
                'contact_id'    => $contactId,
                'account_type'  => 'bank_account',
                'bank_account'  => [
                    'name'           => $vendor['full_name'],
                    'ifsc'           => $ifsc,
                    'account_number' => $accountNumber,
                ],
            ]),
        ]);
        $fund = $this->decodeOrFail($fundResp, 'creating the fund account');
        if (!$fund['success']) return $fund;
        $fundAccountId = $fund['data']['id'] ?? null;
        if (!$fundAccountId) return ['success' => false, 'message' => 'Razorpay did not return a fund account id.'];

        // Step 3: Validation — this is the actual penny-drop request.
        // amount is in paise; Razorpay's minimum for a real bank-account
        // validation is 100 paise (₹1), refunded automatically per
        // Razorpay's own documented behaviour for this product.
        $validationResp = wp_remote_post('https://api.razorpay.com/v1/fund_accounts/validations', [
            'timeout' => 15, 'headers' => $auth,
            'body' => wp_json_encode([
                'account_number' => $accountNumber,
                'fund_account'   => ['id' => $fundAccountId],
                'amount'         => 100,
                'currency'       => 'INR',
                'notes'          => ['rtoflow_vendor_id' => (string)$vendorId],
            ]),
        ]);
        $validation = $this->decodeOrFail($validationResp, 'starting the penny-drop validation');
        if (!$validation['success']) return $validation;

        $updated = $this->db->update($this->p . 'rto_vendors', [
            'bank_verification_status' => 'pending',
            'bank_verification_note'   => 'Verification initiated — awaiting Razorpay confirmation (usually within a few minutes to a few hours).',
            'razorpay_contact_id'      => $contactId,
            'razorpay_fund_account_id' => $fundAccountId,
        ], ['id' => $vendorId]);

        if ($updated === false) {
            error_log('RTOFLOW BankVerificationService: initiated with Razorpay but failed to save status for vendor ' . $vendorId . ' — ' . $this->db->last_error);
            return ['success' => false, 'message' => 'Verification was started with Razorpay but the status could not be saved locally — check the audit log and retry.'];
        }

        AuditService::log('vendor.bank_verification_initiated', null, [
            'vendor_id' => $vendorId, 'fund_account_id' => $fundAccountId,
        ]);

        return ['success' => true, 'message' => 'Bank verification started — Razorpay will confirm shortly.'];
    }

    /**
     * Called from Router::routeWebhook()'s 'razorpay' branch for
     * fund_account.validation.completed / .failed events. Matches purely by
     * fund_account_id, not vendor_id (Razorpay's payload has no idea what
     * "vendor" means) — this is why initiate() stores
     * razorpay_fund_account_id on the vendor row.
     */
    public function handleWebhookEvent(string $eventType, array $payload): void
    {
        $entity = $payload['payload']['fund_account.validation']['entity'] ?? null;
        if (!$entity) return;

        $fundAccountId = $entity['fund_account']['id'] ?? null;
        $status        = $entity['status'] ?? '';
        if (!$fundAccountId) return;

        $vendorId = (int)$this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->p}rto_vendors WHERE razorpay_fund_account_id=%s LIMIT 1",
            $fundAccountId
        ));
        if (!$vendorId) {
            error_log('RTOFLOW BankVerificationService: webhook for unknown fund_account_id ' . $fundAccountId);
            return;
        }

        $newStatus = $status === 'completed' ? 'verified' : 'failed';
        $note = $status === 'completed'
            ? 'Verified by Razorpay on ' . current_time('mysql') . '.'
            : 'Razorpay could not verify this account: ' . ($entity['results']['account_status'] ?? 'validation failed') . '.';

        $this->db->update($this->p . 'rto_vendors', [
            'bank_verification_status' => $newStatus,
            'bank_verified_at'         => $newStatus === 'verified' ? current_time('mysql') : null,
            'bank_verification_note'   => $note,
        ], ['id' => $vendorId]);

        AuditService::log('vendor.bank_verification_' . $newStatus, null, [
            'vendor_id' => $vendorId, 'fund_account_id' => $fundAccountId, 'razorpay_status' => $status,
        ]);
    }

    /** @return array{success:bool,message?:string,data?:array} */
    private function decodeOrFail($response, string $step): array
    {
        if (is_wp_error($response)) {
            error_log('RTOFLOW BankVerificationService: HTTP error ' . $step . ' — ' . $response->get_error_message());
            return ['success' => false, 'message' => 'A network error occurred while ' . $step . '. Please try again.'];
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true) ?: [];
        if ($code < 200 || $code >= 300) {
            $err = $body['error']['description'] ?? ('HTTP ' . $code);
            error_log('RTOFLOW BankVerificationService: Razorpay error ' . $step . ' — ' . $err);
            return ['success' => false, 'message' => 'Razorpay rejected the request while ' . $step . ': ' . $err];
        }
        return ['success' => true, 'data' => $body];
    }
}
