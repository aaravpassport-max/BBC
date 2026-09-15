<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 5, item 2 — "client referral/loyalty program"):
 * a minimal, real referral mechanism over the new rto_wallet_ledger table
 * (migration 2024_01_01_000039). No wallet, credit, or referral mechanism
 * existed anywhere before this — the pre-existing "wallet" was only a
 * Razorpay payment-method label. Referral code/attribution are stored in
 * WP usermeta (no new user-level schema needed); actual value transfer is
 * the ledger, which is the only thing that needs to be tamper-evident.
 */
class ReferralService
{
    const META_CODE        = 'rtoflow_referral_code';
    const META_REFERRED_BY = 'rtoflow_referred_by';

    // TRACE: called from ClientDashboardController::index()/profile() to
    // render the client's own referral link → looks up existing code in
    // usermeta → generates+stores one on first call only (never regenerated,
    // so a link a client already shared keeps working forever) →
    // postconditions: usermeta 'rtoflow_referral_code' is always non-empty
    // for any user this is called for.
    public static function getOrCreateCode(int $userId): string
    {
        $code = get_user_meta($userId, self::META_CODE, true);
        if (is_string($code) && $code !== '') {
            return $code;
        }
        $code = strtoupper(substr(md5($userId . wp_generate_password(6, false)), 0, 8));
        update_user_meta($userId, self::META_CODE, $code);
        return $code;
    }

    public static function referralUrl(int $userId): string
    {
        return home_url('/rto-apply/?ref=' . self::getOrCreateCode($userId));
    }

    // TRACE: called once at registration time (Router.php client-registration
    // arm) with the ?ref= code carried through the signup form →
    // resolves the code to its owning user via a bounded usermeta lookup →
    // preconditions: $newUserId must not already have a referred_by value
    // (checked here, not just by the caller) → postconditions: usermeta
    // 'rtoflow_referred_by' set on the NEW user only, once, ever →
    // edge cases: unknown code → no-op (silently ignored, registration still
    // succeeds); self-referral (code resolves to $newUserId itself) → blocked.
    public static function recordReferral(int $newUserId, string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '' || $newUserId <= 0) return false;

        $already = get_user_meta($newUserId, self::META_REFERRED_BY, true);
        if ($already) return false; // Already attributed — never overwritten

        $users = get_users([
            'meta_key'   => self::META_CODE,
            'meta_value' => $code,
            'number'     => 1,
            'fields'     => 'ID',
        ]);
        if (empty($users)) return false;

        $referrerId = (int)$users[0];
        if ($referrerId === $newUserId) return false; // No self-referral

        update_user_meta($newUserId, self::META_REFERRED_BY, $referrerId);
        return true;
    }

    // TRACE: fired by rtoflow_payment_completed action (Bootstrap::init()
    // dispatcher, wired alongside the other payment-completion hooks) →
    // resolves the lead's client_id → checks referred_by usermeta →
    // dedup-guards via rto_wallet_ledger (ref_type='referral', ref_id=$leadId)
    // so a lead that fires payment_completed more than once (e.g. a partial
    // payment topped up to full) can never double-credit →
    // credits BOTH the referrer and the referred client (a simple two-sided
    // incentive, the common referral-program shape) with the configured
    // amount → preconditions: client was referred AND this is their first
    // credited paid lead → postconditions: 2 new rto_wallet_ledger rows (or
    // 0 if not eligible) → edge cases: no referred_by → no-op; referral
    // already credited for this lead → no-op (idempotent on retry/replay).
    public static function creditOnFirstPayment(int $leadId): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT id, client_id FROM {$p}rto_leads WHERE id = %d", $leadId
        ), ARRAY_A);
        if (!$lead || empty($lead['client_id'])) return;

        $clientId = (int)$lead['client_id'];
        $referrerId = (int)get_user_meta($clientId, self::META_REFERRED_BY, true);
        if (!$referrerId) return;

        $alreadyCredited = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}rto_wallet_ledger WHERE ref_type='referral' AND ref_id=%d",
            $leadId
        ));
        if ($alreadyCredited) return;

        $amount = (float)get_option('rtoflow_referral_credit_amount', 100);
        if ($amount <= 0) return;

        self::credit($referrerId, $amount, 'Referral bonus — a friend you referred completed their first paid order', $leadId);
        self::credit($clientId, $amount, 'Welcome bonus — thanks for using a referral link', $leadId);
    }

    private static function credit(int $userId, float $amount, string $reason, int $leadId): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'rto_wallet_ledger', [
            'user_id'    => $userId,
            'amount'     => $amount,
            'type'       => 'credit',
            'reason'     => $reason,
            'ref_type'   => 'referral',
            'ref_id'     => $leadId,
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function balance(int $userId): float
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $sum = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE -amount END),0)
             FROM {$p}rto_wallet_ledger WHERE user_id = %d",
            $userId
        ));
        return (float)$sum;
    }

    public static function history(int $userId, int $limit = 50): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}rto_wallet_ledger WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $userId, $limit
        ), ARRAY_A) ?: [];
    }

    public static function referralCount(int $userId): int
    {
        return count(get_users([
            'meta_key'   => self::META_REFERRED_BY,
            'meta_value' => $userId,
            'fields'     => 'ID',
        ]));
    }
}
