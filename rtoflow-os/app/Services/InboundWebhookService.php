<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Generic inbound-webhook framework.
 *
 * ENTERPRISE GAP FIX (Phase 12, item — "No inbound webhook framework beyond
 * per-integration handlers"): Razorpay's and WhatsApp's inbound webhook
 * handling each independently implemented "verify a signature header
 * against a stored secret, then act on the payload" directly inside
 * Router::routeWebhook() — real, working code, but bespoke per provider
 * with no shared abstraction, no logging of what came in, and no way for a
 * future integration (a new payment gateway, an SMS DLR callback, a
 * franchise-portal push) to get signature verification "for free" without
 * writing its own crypto code inline in the router again.
 *
 * This service is that shared abstraction:
 *   - verifySignature() implements the two signature schemes already in use
 *     across the codebase (raw HMAC-SHA256 hex, and HMAC-SHA256 with a
 *     'sha256=' prefix as some providers send) so EITHER existing handler
 *     could be rewritten to call this instead of hand-rolling hash_hmac()
 *     + hash_equals() again — see verifyRazorpayStyle()/verifyMetaStyle()
 *     below, which are exactly what Router.php / PaymentService /
 *     RTOFLOW_WhatsApp already do, extracted so the logic exists in one
 *     place instead of two (drifting apart over time otherwise).
 *   - log() records every inbound call (valid or not) to
 *     rto_inbound_webhook_log — previously an invalid Razorpay signature was
 *     just silently rejected with no trace; a rejected/failed inbound call
 *     is now visible to an admin instead of only living in the web server's
 *     own access log.
 *   - registerProvider()/dispatchCustom() let a FUTURE integration register
 *     itself (slug, secret, header name, signature scheme) via the admin
 *     screen with zero PHP changes, and receive inbound calls at the single
 *     generic route '/rto-webhook/custom/{slug}' — verified generically,
 *     logged generically, and handed off via the 'rtoflow_inbound_webhook_{slug}'
 *     WordPress action hook for whatever code needs to react to it. This is
 *     the part that did not exist in any form before this fix: Razorpay and
 *     WhatsApp still keep their own routes/options (changing working
 *     payment/messaging integrations to a generic path is out of scope and
 *     riskier than it's worth) but nothing new needs a bespoke route or a
 *     Router.php change to plug in going forward.
 */
class InboundWebhookService
{
    private \wpdb $db;
    private string $p;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->p  = $wpdb->prefix;
    }

    /** Raw hex HMAC-SHA256 — the scheme Razorpay's X-Razorpay-Signature uses. */
    public static function verifyRazorpayStyle(string $body, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') return false;
        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $signature);
    }

    /** 'sha256=<hex>' prefixed HMAC — the scheme Meta/WhatsApp-style webhooks use. */
    public static function verifyMetaStyle(string $body, string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === '' || $secret === '' || strpos($signatureHeader, 'sha256=') !== 0) return false;
        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, substr($signatureHeader, 7));
    }

    /**
     * Generic verifier used by dispatchCustom() for provider rows registered
     * through the admin screen — dispatches to the right scheme by name so
     * new schemes can be added here in one place as they're needed.
     */
    public function verifySignature(string $scheme, string $body, string $signatureHeader, string $secret): bool
    {
        return match ($scheme) {
            'hmac_sha256_hex'    => self::verifyRazorpayStyle($body, $signatureHeader, $secret),
            'hmac_sha256_prefix' => self::verifyMetaStyle($body, $signatureHeader, $secret),
            default              => false,
        };
    }

    /**
     * Records one inbound call. Called by both this class's own
     * dispatchCustom() and (optionally) by the existing Razorpay/WhatsApp
     * handlers in Router.php, so all inbound integration traffic — old and
     * new — ends up visible in one place.
     */
    public function log(string $provider, bool $signatureValid, string $body, ?string $eventType = null, ?string $error = null, int $httpStatus = 200): int
    {
        $this->db->insert($this->p . 'rto_inbound_webhook_log', [
            'provider'        => substr($provider, 0, 60),
            'event_type'      => $eventType !== null ? substr($eventType, 0, 120) : null,
            'signature_valid' => $signatureValid ? 1 : 0,
            'http_status'     => $httpStatus,
            'remote_ip'       => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'payload'         => substr($body, 0, 65000),
            'error_message'   => $error !== null ? substr($error, 0, 500) : null,
            'created_at'      => current_time('mysql'),
        ]);
        return (int)$this->db->insert_id;
    }

    /** Provider rows for the generic '/rto-webhook/custom/{slug}' route. */
    public function findProvider(string $slug): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->p}rto_inbound_webhook_providers WHERE slug = %s AND is_active = 1",
            $slug
        ), ARRAY_A);
        return $row ?: null;
    }

    public function listProviders(): array
    {
        return $this->db->get_results(
            "SELECT * FROM {$this->p}rto_inbound_webhook_providers ORDER BY created_at DESC",
            ARRAY_A
        ) ?: [];
    }

    public function registerProvider(string $slug, string $label, string $secret, string $signatureHeader, string $signatureScheme, int $createdBy): array
    {
        $slug = strtolower(preg_replace('/[^a-z0-9\-]/i', '-', trim($slug)) ?? '');
        if ($slug === '' || $label === '' || $secret === '') {
            return ['success' => false, 'message' => 'Slug, label and secret are all required.'];
        }
        if ($this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->p}rto_inbound_webhook_providers WHERE slug = %s", $slug
        ))) {
            return ['success' => false, 'message' => 'A provider with this slug already exists.'];
        }

        $this->db->insert($this->p . 'rto_inbound_webhook_providers', [
            'slug'             => $slug,
            'label'            => substr($label, 0, 150),
            'secret'           => $secret,
            'signature_header' => $signatureHeader ?: 'X-Signature',
            'signature_scheme' => $signatureScheme ?: 'hmac_sha256_hex',
            'is_active'        => 1,
            'created_at'       => current_time('mysql'),
            'created_by'       => $createdBy ?: null,
        ]);

        $url = home_url('/rto-webhook/custom/' . $slug . '/');
        return ['success' => true, 'message' => 'Provider registered.', 'slug' => $slug, 'url' => $url];
    }

    public function toggleProvider(int $id, bool $active): void
    {
        $this->db->update($this->p . 'rto_inbound_webhook_providers', ['is_active' => $active ? 1 : 0], ['id' => $id]);
    }

    /**
     * Called from Router::routeWebhook()'s 'custom' branch. Verifies the
     * request against the registered provider's stored secret/scheme, logs
     * it either way, and — only on a valid signature — fires
     * 'rtoflow_inbound_webhook_{slug}' with the decoded JSON payload (or the
     * raw body if it isn't JSON) so any code, including a future plugin
     * add-on, can hook in without touching this class or the router again.
     */
    public function dispatchCustom(string $slug, string $body, array $headers): array
    {
        $provider = $this->findProvider($slug);
        if (!$provider) {
            return ['status' => 404, 'message' => 'Unknown or inactive webhook provider.'];
        }

        $headerName = strtolower(str_replace('-', '_', $provider['signature_header']));
        $signature  = $headers[$headerName] ?? $headers[$provider['signature_header']] ?? '';

        $valid = $this->verifySignature($provider['signature_scheme'], $body, (string)$signature, (string)$provider['secret']);

        $decoded   = json_decode($body, true);
        $eventType = is_array($decoded) ? ($decoded['event'] ?? $decoded['type'] ?? null) : null;

        $this->log($slug, $valid, $body, $eventType, $valid ? null : 'Signature verification failed', $valid ? 200 : 401);

        if (!$valid) {
            return ['status' => 401, 'message' => 'Invalid signature.'];
        }

        do_action('rtoflow_inbound_webhook_' . $slug, is_array($decoded) ? $decoded : $body, $headers);

        return ['status' => 200, 'message' => 'OK'];
    }

    /** Recent log rows for the admin screen. */
    public function recentLog(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->get_results(
            "SELECT * FROM {$this->p}rto_inbound_webhook_log ORDER BY created_at DESC LIMIT {$limit}",
            ARRAY_A
        ) ?: [];
    }
}
