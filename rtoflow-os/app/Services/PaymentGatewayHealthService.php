<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 12, item — "Single-provider dependency for SMS,
 * WhatsApp, and payments ... no fallback provider exists for any of the
 * three critical external dependencies"): SMS/WhatsApp already got an
 * automatic failover in Phase 4 (RTOFLOW_WhatsApp::fallbackToSms() —
 * see integrations/WhatsApp.php). Payments are the one of the three left
 * genuinely unaddressed: Razorpay is the only online gateway, and there is
 * no second live gateway integrated in this codebase to fail over to —
 * building one (a real Stripe/PayU/Cashfree integration with its own keys,
 * webhook verification, and reconciliation) is a multi-week project on its
 * own, not something this fix fabricates.
 *
 * What genuinely closes the operational half of the gap: this service
 * tracks Razorpay order-creation failures in a short rolling window and,
 * once a real outage is detected (not a single blip — a client mistyping
 * something never touches this path, only actual gateway-call failures
 * do), (a) surfaces a clear "pay by other means" fallback message to the
 * client instead of a bare error, and (b) alerts an admin once per
 * detected-outage window so staff can proactively start recording manual/
 * offline payments (PaymentService::record() already supports a 'manual'
 * method — cash/bank-transfer collected by staff — which is the real,
 * already-working fallback payment PATH; what was missing was automatic
 * detection + surfacing it instead of a client just seeing a dead-end
 * error with no explanation).
 */
class PaymentGatewayHealthService
{
    private const FAILURE_WINDOW_SECONDS = 600; // 10 minutes
    private const FAILURE_THRESHOLD      = 3;   // 3 failures inside the window = "outage"
    private const ALERT_COOLDOWN_SECONDS = 3600; // don't re-alert more than once/hour

    /**
     * Call this every time RTOFLOW_Razorpay::createOrder() (or any future
     * gateway call) fails. Returns true the FIRST time this call trips the
     * outage threshold (so the caller can act on it once, not on every
     * failure) — subsequent failures inside the same window return false.
     */
    public static function recordFailure(string $context = ''): bool
    {
        $now      = time();
        $failures = get_transient('rtoflow_pg_failures') ?: [];
        $failures = array_filter($failures, static fn($ts) => $ts > $now - self::FAILURE_WINDOW_SECONDS);
        $failures[] = $now;
        set_transient('rtoflow_pg_failures', $failures, self::FAILURE_WINDOW_SECONDS);

        if (count($failures) < self::FAILURE_THRESHOLD) {
            return false;
        }

        $lastAlert = (int)get_transient('rtoflow_pg_outage_alerted');
        if ($lastAlert && $lastAlert > $now - self::ALERT_COOLDOWN_SECONDS) {
            return false; // already alerted recently, don't spam
        }
        set_transient('rtoflow_pg_outage_alerted', $now, self::ALERT_COOLDOWN_SECONDS);

        AuditService::log('payment_gateway.outage_detected', null, [
            'failures_in_window' => count($failures),
            'window_seconds'     => self::FAILURE_WINDOW_SECONDS,
            'context'            => $context,
        ]);

        $adminEmail = get_option('rtoflow_admin_email', get_option('admin_email'));
        if ($adminEmail) {
            wp_mail(
                $adminEmail,
                '[RTOFlow] Payment gateway outage detected',
                "Razorpay order creation has failed " . count($failures) . " times in the last " .
                (int)(self::FAILURE_WINDOW_SECONDS / 60) . " minutes. Clients are being shown a fallback " .
                "message directing them to contact support for manual payment collection. " .
                "Check the Razorpay dashboard / API status and this site's Settings > Payment Gateway config."
            );
        }

        return true;
    }

    /** Whether we're currently inside a detected-outage window (for client-facing messaging). */
    public static function isDegraded(): bool
    {
        $failures = get_transient('rtoflow_pg_failures') ?: [];
        $now      = time();
        $failures = array_filter($failures, static fn($ts) => $ts > $now - self::FAILURE_WINDOW_SECONDS);
        return count($failures) >= self::FAILURE_THRESHOLD;
    }

    /** Call on a successful order creation to clear the failure count. */
    public static function recordSuccess(): void
    {
        delete_transient('rtoflow_pg_failures');
    }
}
