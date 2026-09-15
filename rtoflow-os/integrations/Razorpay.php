<?php

if (!defined('ABSPATH')) exit;

/**
 * Razorpay Payment Gateway Integration
 *
 * Handles order creation, payment verification, and refunds
 * using the Razorpay REST API.
 *
 * Configuration (from .env):
 *   RAZORPAY_KEY=rzp_live_xxx
 *   RAZORPAY_SECRET=your_secret
 *   RAZORPAY_WEBHOOK_SECRET=your_webhook_secret
 *   RAZORPAY_MODE=live  (or test)
 */
class RTOFLOW_Razorpay
{
    private const API_BASE = 'https://api.razorpay.com/v1/';

    // ── Credential resolution ───────────────────────────────────────────────
    //
    // FIX P0-9: every credential read in this class previously came ONLY from
    // \RTOFLOW\Config\Env (.env file / wp-config define()s). But the admin
    // Settings → Payment screen (SettingsController::save()) has always saved
    // Razorpay key/secret to wp_options under 'rtoflow_razorpay_key_id' /
    // 'rtoflow_razorpay_key_secret' — options this class never read. The
    // practical effect: an admin filling in the Settings UI (exactly what the
    // README documents as the supported configuration path) had zero effect
    // on whether Razorpay actually worked; only a developer manually editing
    // a .env file could make payments function. These helpers make wp_options
    // (with the secret encrypted at rest) the working configuration path,
    // while still letting a .env value override it for environments that
    // prefer keeping secrets out of the database entirely.
    private static function getKey(): string
    {
        $env = \RTOFLOW\Config\Env::string('RAZORPAY_KEY', '');
        return $env !== '' ? $env : (string)get_option('rtoflow_razorpay_key_id', '');
    }

    private static function getSecret(): string
    {
        $env = \RTOFLOW\Config\Env::string('RAZORPAY_SECRET', '');
        if ($env !== '') return $env;

        $encrypted = (string)get_option('rtoflow_razorpay_key_secret_enc', '');
        if ($encrypted !== '') {
            return \RTOFLOW\Security\Encryption::decryptSafe($encrypted);
        }
        // One-release fallback for secrets saved before this fix.
        return (string)get_option('rtoflow_razorpay_key_secret', '');
    }

    private static function getMode(): string
    {
        $env = \RTOFLOW\Config\Env::string('RAZORPAY_MODE', '');
        return $env !== '' ? $env : (string)get_option('rtoflow_razorpay_mode', 'test');
    }

    // ── Create order ──────────────────────────────────────────────────────

    /**
     * Create a Razorpay order for a lead.
     *
     * @param float  $amount   Amount in INR (NOT paise)
     * @param int    $leadId   Internal lead ID
     * @param array  $notes    Additional metadata
     * @return array ['success', 'order_id', 'key', 'amount', 'currency', 'message']
     */
    public static function createOrder(float $amount, int $leadId, array $notes = []): array
    {
        if (!self::isEnabled()) {
            return ['success' => false, 'message' => 'Razorpay is not configured.'];
        }

        $payload = [
            'amount'          => (int)round($amount * 100), // Razorpay uses paise
            'currency'        => 'INR',
            'receipt'         => 'rto_' . $leadId . '_' . time(),
            'notes'           => array_merge($notes, ['lead_id' => $leadId]),
            'payment_capture' => 1,
        ];

        $response = self::call('POST', 'orders', $payload);

        if (!$response['success']) {
            return $response;
        }

        $order = $response['data'];

        return [
            'success'  => true,
            'order_id' => $order['id'],
            'key'      => self::getKey(),
            'amount'   => $order['amount'],
            'currency' => $order['currency'],
            'message'  => 'Order created successfully.',
        ];
    }

    // ── Verify payment ────────────────────────────────────────────────────

    /**
     * Verify payment signature from Razorpay checkout callback.
     * MUST be called before recording any payment.
     */
    public static function verifyPayment(string $orderId, string $paymentId, string $signature): bool
    {
        $secret   = self::getSecret();
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
        return hash_equals($expected, $signature);
    }

    // ── Refund ────────────────────────────────────────────────────────────

    public static function refund(string $paymentId, float $amount): array
    {
        $payload = ['amount' => (int)round($amount * 100)];
        $result  = self::call('POST', "payments/{$paymentId}/refund", $payload);

        if (!$result['success']) return $result;

        return [
            'success'    => true,
            'refund_id'  => $result['data']['id'] ?? '',
            'message'    => 'Refund initiated successfully.',
        ];
    }

    // ── Fetch payment ─────────────────────────────────────────────────────

    public static function fetchPayment(string $paymentId): array
    {
        return self::call('GET', "payments/{$paymentId}");
    }

    // ── Status ────────────────────────────────────────────────────────────

    public static function isEnabled(): bool
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('razorpay_payments')) {
            return false;
        }
        return !empty(self::getKey()) && !empty(self::getSecret());
    }

    public static function mode(): string
    {
        return self::getMode();
    }

    // ── Internal API caller ───────────────────────────────────────────────

    private static function call(string $method, string $endpoint, array $body = []): array
    {
        $key    = self::getKey();
        $secret = self::getSecret();
        $url    = self::API_BASE . $endpoint;

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$key}:{$secret}"),
                'Content-Type'  => 'application/json',
            ],
        ];

        if ($method === 'POST' && !empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            error_log('RTOFLOW Razorpay: ' . $response->get_error_message());
            return ['success' => false, 'message' => 'Gateway connection failed. Please try again.'];
        }

        $code    = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $data    = json_decode($rawBody, true) ?: [];

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'data' => $data];
        }

        $errorDesc = $data['error']['description'] ?? "HTTP {$code}";
        error_log("RTOFLOW Razorpay [{$endpoint}]: {$errorDesc}");
        return ['success' => false, 'message' => 'Payment gateway error: ' . $errorDesc];
    }
}
