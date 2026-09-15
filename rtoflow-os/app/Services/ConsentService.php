<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Cookie / Tracking Consent Service
 *
 * ENTERPRISE GAP FIX (Phase 9, item — "no cookie-consent / tracking-consent
 * banner"): consent was previously captured only as a per-form "I agree to
 * terms" checkbox at lead submission — there was no site-wide, revocable,
 * auditable consent record for analytics/tracking scripts. This service is
 * that record: every accept/reject/revoke decision writes a fresh row (an
 * insert-only trail, matching AuditService's own "never update, never
 * delete" evidentiary approach) keyed by an anonymous per-browser token, so
 * it works for a logged-out visitor as well as a logged-in user. Each
 * decision is also mirrored into the main audit log via AuditService so
 * consent changes show up alongside every other auditable action.
 *
 * The banner itself (resources/views/partials/cookie-consent.php +
 * resources/assets/js/cookie-consent.js) only controls whether
 * analytics/marketing scripts are allowed to load — it has no effect on
 * "necessary" cookies (session/auth/nonce), which are not a consent choice
 * under GDPR/DPDP and are never gated by this.
 */
class ConsentService
{
    public const COOKIE_NAME = 'rto_consent_token';

    /**
     * Look up the most recent, non-revoked consent decision for a token.
     * Returns null if no decision has ever been recorded (banner should
     * still be shown).
     *
     * @return array{analytics:bool,marketing:bool,created_at:string}|null
     */
    public static function current(string $token): ?array
    {
        if ($token === '') return null;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT analytics, marketing, created_at FROM {$wpdb->prefix}rto_cookie_consents
             WHERE token = %s AND revoked_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $token
        ), ARRAY_A);
        if (!$row) return null;
        return [
            'analytics'  => (bool)$row['analytics'],
            'marketing'  => (bool)$row['marketing'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Record a new consent decision. Always inserts a new row (an
     * insert-only, revocable trail) rather than updating a prior one, and
     * mirrors the decision into AuditService so it appears in the same
     * place every other auditable action does.
     */
    public static function record(string $token, bool $analytics, bool $marketing, ?int $userId = null): void
    {
        global $wpdb;
        $userId ??= get_current_user_id() ?: null;

        $wpdb->insert($wpdb->prefix . 'rto_cookie_consents', [
            'token'      => $token,
            'user_id'    => $userId,
            'analytics'  => $analytics ? 1 : 0,
            'marketing'  => $marketing ? 1 : 0,
            'ip_hash'    => self::ipHash(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'created_at' => current_time('mysql'),
        ]);

        AuditService::log('consent.recorded', null, [
            'token'     => $token,
            'analytics' => $analytics,
            'marketing' => $marketing,
        ], [], $userId);
    }

    /**
     * Revoke every currently-live consent row for a token (e.g. a visitor
     * later opts out from a preferences link). Auditable and non-destructive
     * — rows are marked revoked_at, never deleted.
     */
    public static function revoke(string $token): void
    {
        if ($token === '') return;
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rto_cookie_consents',
            ['revoked_at' => current_time('mysql')],
            ['token' => $token, 'revoked_at' => null]
        );
        AuditService::log('consent.revoked', null, ['token' => $token]);
    }

    /** SHA-256 hash of the visitor IP — the audit trail needs a stable
     *  "was this the same visitor" signal without storing the raw IP
     *  alongside a tracking-consent record, which would itself be a
     *  privacy footgun. */
    private static function ipHash(): string
    {
        $ip = \RTOFLOW\Http\Middleware\RateLimiter::clientIp();
        return hash('sha256', $ip);
    }
}
