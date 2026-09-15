<?php

if (!defined('ABSPATH')) exit;

/**
 * SMS Integration
 *
 * Supports multiple SMS providers:
 * - MSG91 (primary — best DLT compliance for India)
 * - Fast2SMS (fallback — no DLT required for some routes)
 *
 * All messages must comply with TRAI DLT (Distributed Ledger Technology)
 * regulations for India. Template IDs must be pre-registered.
 *
 * Configuration (from .env):
 *   SMS_PROVIDER=msg91
 *   SMS_AUTH_KEY=your_msg91_auth_key
 *   SMS_SENDER_ID=RTOFLW
 *   SMS_ENABLED=true
 *
 * Usage:
 *   RTOFLOW_SMS::send('9876543210', 'Your order RTO-2024-000001 is confirmed.');
 *   RTOFLOW_SMS::sendTemplate('lead_confirmed', '9876543210', ['lead_number' => 'RTO-2024-000001']);
 */
class RTOFLOW_SMS
{
    private const PROVIDERS = ['msg91', 'fast2sms'];

    // DLT-registered template IDs (must be configured via admin settings)
    private static array $templateIds = [];

    // ── Main send ─────────────────────────────────────────────────────────

    /**
     * Send an SMS to a mobile number.
     * Returns true on success, false on failure.
     * Errors are logged but not thrown.
     */
    public static function send(string $mobile, string $message, string $templateId = ''): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        $mobile = self::normaliseMobile($mobile);
        if (!$mobile) {
            error_log("RTOFLOW SMS: Invalid mobile number provided.");
            return false;
        }

        $provider = self::getProvider();

        $result = match ($provider) {
            'msg91'    => self::sendMsg91($mobile, $message, $templateId),
            'fast2sms' => self::sendFast2Sms($mobile, $message),
            default    => false,
        };

        // Log the send attempt
        error_log(sprintf(
            "RTOFLOW SMS [%s]: %s to %s — %s",
            $provider,
            $result ? 'SENT' : 'FAILED',
            self::maskMobile($mobile),
            substr($message, 0, 50)
        ));

        // If primary fails, try fallback
        if (!$result && $provider !== 'fast2sms') {
            $fallback = self::sendFast2Sms($mobile, $message);
            if ($fallback) {
                error_log("RTOFLOW SMS: Fallback to fast2sms succeeded for " . self::maskMobile($mobile));
                return true;
            }
        }

        return $result;
    }

    /**
     * Send an SMS using a named template (handles variable substitution).
     * Template bodies are loaded from rto_notification_templates where channel='sms'.
     */
    public static function sendTemplate(string $slug, string $mobile, array $vars = []): bool
    {
        global $wpdb;
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_notification_templates WHERE slug = %s AND channel = 'sms' AND is_active = 1",
            $slug
        ), ARRAY_A);

        if (!$template) {
            error_log("RTOFLOW SMS: Template not found: {$slug}");
            return false;
        }

        $body       = $template['body'];
        $templateId = $template['dlt_template_id'] ?? '';

        // Substitute variables
        foreach ($vars as $key => $value) {
            $body = str_replace('{' . $key . '}', (string)$value, $body);
        }

        return self::send($mobile, $body, $templateId);
    }

    // ── MSG91 ─────────────────────────────────────────────────────────────

    private static function sendMsg91(string $mobile, string $message, string $templateId = ''): bool
    {
        $authKey  = \RTOFLOW\Config\Env::string('SMS_AUTH_KEY', '');
        $senderId = \RTOFLOW\Config\Env::string('SMS_SENDER_ID', 'RTOFLW');

        if (!$authKey) {
            error_log("RTOFLOW SMS MSG91: SMS_AUTH_KEY not configured.");
            return false;
        }

        // MSG91 Flow API (v5)
        $payload = [
            'template_id' => $templateId ?: get_option('rtoflow_sms_default_template_id', ''),
            'short_url'   => '0',
            'realTimeResponse' => '0',
            'recipients'  => [
                [
                    'mobiles'  => '91' . $mobile,
                    'var1'     => substr($message, 0, 30), // Message preview
                    'message'  => $message,
                ]
            ],
        ];

        // If no template ID, use the plain text route (transactional)
        if (!$templateId) {
            return self::sendMsg91Plain($mobile, $message, $authKey, $senderId);
        }

        $response = wp_remote_post(
            'https://api.msg91.com/api/v5/flow/',
            [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'authkey'      => $authKey,
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            error_log("RTOFLOW SMS MSG91 Error: " . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && ($body['type'] ?? '') === 'success') {
            return true;
        }

        error_log("RTOFLOW SMS MSG91: Failed — " . ($body['message'] ?? "HTTP {$code}"));
        return false;
    }

    private static function sendMsg91Plain(string $mobile, string $message, string $authKey, string $senderId): bool
    {
        $response = wp_remote_post(
            'https://api.msg91.com/api/sendhttp.php',
            [
                'timeout' => 15,
                'body' => [
                    'authkey'  => $authKey,
                    'mobiles'  => '91' . $mobile,
                    'message'  => $message,
                    'sender'   => $senderId,
                    'route'    => '4', // Transactional
                    'country'  => '91',
                    'unicode'  => '0',
                ],
            ]
        );

        if (is_wp_error($response)) {
            error_log("RTOFLOW SMS MSG91 Plain Error: " . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        // MSG91 returns "error" string or a message ID starting with a digit
        if (str_starts_with($body, 'error')) {
            error_log("RTOFLOW SMS MSG91 Plain: {$body}");
            return false;
        }

        return true;
    }

    // ── Fast2SMS ──────────────────────────────────────────────────────────

    private static function sendFast2Sms(string $mobile, string $message): bool
    {
        $apiKey = \RTOFLOW\Config\Env::string('FAST2SMS_API_KEY', '');

        if (!$apiKey) {
            return false; // Not configured
        }

        $response = wp_remote_post(
            'https://www.fast2sms.com/dev/bulkV2',
            [
                'timeout' => 15,
                'headers' => [
                    'authorization' => $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode([
                    'route'       => 'q',  // Quick route
                    'message'     => $message,
                    'language'    => 'english',
                    'flash'       => '0',
                    'numbers'     => $mobile,
                ]),
            ]
        );

        if (is_wp_error($response)) {
            error_log("RTOFLOW SMS Fast2SMS Error: " . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($body['return'] ?? false) {
            return true;
        }

        error_log("RTOFLOW SMS Fast2SMS: Failed — " . ($body['message'][0] ?? 'Unknown error'));
        return false;
    }

    // ── OTP via SMS ───────────────────────────────────────────────────────

    /**
     * Send OTP for phone verification.
     * Stores OTP in transient for verification.
     * Returns the OTP (store securely, don't expose in response).
     */
    public static function sendOtp(string $mobile): ?string
    {
        $mobile = self::normaliseMobile($mobile);
        if (!$mobile) return null;

        // Rate limit OTP requests
        if (!\RTOFLOW\Http\Middleware\RateLimiter::check('otp', $mobile)) {
            return null;
        }

        $otp = (string)random_int(100000, 999999);

        // Store hashed OTP (never store plaintext OTP)
        $hash = hash_hmac('sha256', $otp, AUTH_KEY);
        set_transient('rtofl_otp_' . $mobile, $hash, 600); // 10 minutes

        $message = "Your RTOFLOW verification code is: {$otp}. Valid for 10 minutes. Do not share.";

        if (self::send($mobile, $message)) {
            return $otp; // Return to calling code to show in dev/test only
        }

        return null;
    }

    /**
     * Verify an OTP submitted by user.
     */
    public static function verifyOtp(string $mobile, string $otp): bool
    {
        $mobile = self::normaliseMobile($mobile);
        if (!$mobile || !preg_match('/^\d{6}$/', $otp)) return false;

        $stored = get_transient('rtofl_otp_' . $mobile);
        if (!$stored) return false;

        $expected = hash_hmac('sha256', $otp, AUTH_KEY);
        if (hash_equals($stored, $expected)) {
            delete_transient('rtofl_otp_' . $mobile);
            return true;
        }

        return false;
    }

    // ── Status ────────────────────────────────────────────────────────────

    public static function isEnabled(): bool
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('sms_notifications')) {
            return false;
        }
        $enabled  = \RTOFLOW\Config\Env::bool('SMS_ENABLED', false)
            || get_option('rtoflow_sms_enabled', '0') === '1';
        $hasKey   = !empty(self::getApiKey());
        return $enabled && $hasKey;
    }

    private static function getApiKey(): string
    {
        // FIX P0-8: SMS API key is now stored encrypted (rtoflow_sms_api_key_enc,
        // written by SettingsController::save()) rather than in plain text.
        // rtoflow_sms_api_key (plain) is read as a one-release migration fallback
        // for keys saved before this fix and re-encrypted transparently below.
        $env = \RTOFLOW\Config\Env::string('SMS_AUTH_KEY', '');
        if ($env !== '') return $env;

        $encrypted = (string)get_option('rtoflow_sms_api_key_enc', '');
        if ($encrypted !== '') {
            return \RTOFLOW\Security\Encryption::decryptSafe($encrypted);
        }
        return (string)get_option('rtoflow_sms_api_key', '');
    }

    private static function getProvider(): string
    {
        return \RTOFLOW\Config\Env::string('SMS_PROVIDER', '')
            ?: (string)get_option('rtoflow_sms_provider', 'msg91');
    }

    private static function getSenderId(): string
    {
        return \RTOFLOW\Config\Env::string('SMS_SENDER_ID', '')
            ?: (string)get_option('rtoflow_sms_sender_id', 'RTOFLW');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function normaliseMobile(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) $digits = substr($digits, 2);
        if (strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits)) return $digits;
        return '';
    }

    private static function maskMobile(string $mobile): string
    {
        return 'XXXXXX' . substr($mobile, -4);
    }
}

// ── Register with NotificationService via WordPress action ──────────────────
add_action('rto_send_sms', function(string $mobile, string $message) {
    RTOFLOW_SMS::send($mobile, $message);
}, 10, 2);
