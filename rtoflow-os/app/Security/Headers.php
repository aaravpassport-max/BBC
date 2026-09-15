<?php

namespace RTOFLOW\Security;

use RTOFLOW\Config\Env;

if (!defined('ABSPATH')) exit;

/**
 * Security Response Headers
 *
 * Sets all recommended security headers on every response.
 * Called once from Bootstrap on template_redirect.
 *
 * Headers set:
 * - Content-Security-Policy (nonce-based script-src; see nonce())
 * - Strict-Transport-Security (HSTS)
 * - X-Frame-Options
 * - X-Content-Type-Options
 * - Referrer-Policy
 * - Permissions-Policy
 * - Cache-Control for authenticated pages
 */
final class Headers
{
    private static ?string $nonce = null;

    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    public static function send(): void
    {
        // FIX P0-4: previously this returned before setting ANY header on AJAX
        // requests, silently defeating Router::dispatchAjax()/dispatchPublicAjax()
        // which call Headers::send() expecting security headers on every JSON
        // response (payment, upload, and status-change endpoints included).
        // AJAX responses get their own header set (below) instead of the full
        // page CSP, since a JSON response has no script/style to police.
        if (wp_doing_ajax()) {
            self::sendAjaxHeaders();
            return;
        }
        if (defined('DOING_CRON') && DOING_CRON) return;
        if (defined('REST_REQUEST') && REST_REQUEST) {
            self::sendApiHeaders();
            return;
        }

        if (headers_sent()) return;

        // ── HSTS ──────────────────────────────────────────────────────────
        // Only send on HTTPS to avoid locking out HTTP visitors
        if (is_ssl()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // ── Clickjacking protection ────────────────────────────────────────
        header('X-Frame-Options: SAMEORIGIN');

        // ── MIME sniffing prevention ───────────────────────────────────────
        header('X-Content-Type-Options: nosniff');

        // ── Referrer ──────────────────────────────────────────────────────
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // ── Permissions Policy ────────────────────────────────────────────
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');

        // ── CSP ───────────────────────────────────────────────────────────
        // ENTERPRISE GAP FIX (Phase 9, item — "CSP still allows inline
        // scripts"): script-src previously carried 'unsafe-inline' even
        // though nonce infrastructure (self::nonce()) already existed —
        // it was generated but never actually wired into the CSP header
        // or emitted on any inline <script> tag, so it did nothing. Every
        // inline <script> block across resources/views/ now carries
        // a nonce="..." attribute set to esc_attr(Headers::nonce()), and script-src below
        // trusts only that per-request nonce plus the explicit external
        // hosts the app actually loads — 'unsafe-inline' is dropped from
        // script-src, closing the inline-script XSS gap. style-src keeps
        // 'unsafe-inline' (inline style attributes are pervasive across
        // views and not a meaningful XSS vector on their own).
        $appUrl = Env::string('APP_URL', home_url());
        $nonce  = self::nonce();
        $csp = implode('; ', [
            "default-src 'self' {$appUrl}",
            "script-src 'self' 'nonce-{$nonce}' https://checkout.razorpay.com https://js.stripe.com https://www.googletagmanager.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "img-src 'self' data: https:",
            "font-src 'self' https://fonts.gstatic.com",
            "connect-src 'self' https://api.razorpay.com https://checkout.razorpay.com wss:",
            "frame-src https://api.razorpay.com https://checkout.razorpay.com",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
        header("Content-Security-Policy: {$csp}");

        // ── Cache control for authenticated pages ─────────────────────────
        if (is_user_logged_in()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
        }

        // ── Remove PHP version leakage ────────────────────────────────────
        header_remove('X-Powered-By');
    }

    private static function sendApiHeaders(): void
    {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store, private');
        header_remove('X-Powered-By');
    }

    /**
     * FIX P0-4: security headers for admin-ajax.php JSON responses.
     * Applies the subset of headers meaningful for a JSON payload —
     * no CSP script-src (there's no script/style to police in a JSON
     * response) but nosniff/frame-options/referrer/cache/HSTS all apply
     * and were previously never sent on this path at all.
     */
    private static function sendAjaxHeaders(): void
    {
        if (headers_sent()) return;
        if (is_ssl()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header_remove('X-Powered-By');
    }
}
