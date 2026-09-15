<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Audit Service — Immutable Action Logging
 *
 * Records every significant action: status changes, payments, document
 * uploads, admin overrides, logins. Audit records are INSERT-only (never
 * updated or deleted) to provide a tamper-evident trail.
 *
 * Usage:
 *   AuditService::log('lead.status_changed', $leadId, ['from' => 'created', 'to' => 'assigned']);
 *   AuditService::logPayment($leadId, $amount, 'razorpay');
 */
class AuditService
{
    // ── Generic log ───────────────────────────────────────────────────────

    public static function log(
        string $action,
        ?int   $leadId    = null,
        array  $newValue  = [],
        array  $oldValue  = [],
        ?int   $userId    = null
    ): void {
        global $wpdb;
        $userId ??= get_current_user_id();

        $wpdb->insert($wpdb->prefix . 'rto_logs', [
            'lead_id'    => $leadId,
            'user_id'    => $userId ?: null,
            'action'     => $action,
            'old_value'  => $oldValue ? wp_json_encode($oldValue) : null,
            'new_value'  => $newValue ? wp_json_encode($newValue) : null,
            'ip_address' => self::ip(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'created_at' => current_time('mysql'),
        ]);
    }

    // ── Specific log helpers ───────────────────────────────────────────────

    public static function logStatusChange(int $leadId, string $from, string $to, string $note = ''): void
    {
        self::log('lead.status_changed', $leadId, ['status' => $to, 'note' => $note], ['status' => $from]);
    }

    public static function logPayment(int $leadId, float $amount, string $method, string $txnId = ''): void
    {
        self::log('payment.recorded', $leadId, ['amount' => $amount, 'method' => $method, 'txn_id' => $txnId]);
    }

    public static function logDocumentAction(int $leadId, string $action, int $docId, string $reason = ''): void
    {
        self::log("document.{$action}", $leadId, ['doc_id' => $docId, 'reason' => $reason]);
    }

    public static function logVendorAssigned(int $leadId, int $vendorId): void
    {
        self::log('lead.vendor_assigned', $leadId, ['vendor_id' => $vendorId]);
    }

    public static function logLogin(int $userId, bool $twoFa = false): void
    {
        self::log('auth.login', null, ['2fa' => $twoFa], [], $userId);
    }

    public static function logLoginFailed(string $username): void
    {
        self::log('auth.login_failed', null, ['username' => $username], [], null);
    }

    // ── Read ──────────────────────────────────────────────────────────────

    public static function forLead(int $leadId, int $limit = 50): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, u.display_name as user_name
             FROM {$wpdb->prefix}rto_logs l
             LEFT JOIN {$wpdb->prefix}users u ON u.ID = l.user_id
             WHERE l.lead_id = %d
             ORDER BY l.created_at DESC
             LIMIT %d",
            $leadId, $limit
        ), ARRAY_A) ?: [];
    }

    public static function forUser(int $userId, int $limit = 50): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_logs
             WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $userId, $limit
        ), ARRAY_A) ?: [];
    }

    private static function ip(): string
    {
        return \RTOFLOW\Http\Middleware\RateLimiter::clientIp();
    }
}
