<?php

if (!defined('ABSPATH')) exit;

/**
 * WhatsApp Business API Integration
 *
 * Uses the Meta Cloud API (v18+) for sending WhatsApp messages.
 * Supports text messages and pre-approved template messages.
 *
 * Prerequisites:
 *   - Verified WhatsApp Business Account
 *   - Approved message templates (for outbound)
 *   - Phone number ID from Meta Business Manager
 *
 * Configuration (from .env):
 *   WHATSAPP_PHONE_ID=your_phone_number_id
 *   WHATSAPP_TOKEN=your_permanent_access_token
 *   WHATSAPP_ENABLED=true
 */
class RTOFLOW_WhatsApp
{
    private const API_VERSION = 'v18.0';
    private const API_BASE    = 'https://graph.facebook.com/';

    // ── Send text message ─────────────────────────────────────────────────

    public static function send(string $mobile, string $message): bool
    {
        if (!self::isEnabled()) return self::fallbackToSms($mobile, $message, 'whatsapp_disabled');

        $mobile = self::normaliseMobile($mobile);
        if (!$mobile) return false;

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => '91' . $mobile,
            'type'              => 'text',
            'text'              => ['body' => $message],
        ];

        $ok = self::call($payload);
        if (!$ok) {
            return self::fallbackToSms($mobile, $message, 'whatsapp_send_failed');
        }
        return true;
    }

    // ENTERPRISE GAP FIX (Phase 4, item 7 — "no fallback provider for
    // SMS/WhatsApp"): this codebase's SMS layer already had a real secondary
    // vendor (RTOFLOW_SMS falls back from MSG91 to Fast2SMS — see
    // integrations/Sms.php::send()); WhatsApp had none — if the single Meta
    // Cloud API integration was down, disabled, or unconfigured, the message
    // was simply dropped with only an error_log line. There is no second
    // commercial WhatsApp Business API vendor already wired into this
    // codebase to fail over to, so the actionable fix here is a cross-channel
    // failover: any WhatsApp send that is skipped (feature/flag/config
    // disabled) or that the Meta API call itself fails now automatically
    // retries as a plain SMS via RTOFLOW_SMS::send() (which in turn already
    // has its own MSG91→Fast2SMS failover), so a client/vendor still gets the
    // notification through some channel instead of losing it outright.
    // Can be disabled via the 'rtoflow_wa_sms_fallback' option for operators
    // who explicitly do not want WhatsApp failures to consume SMS credits.
    private static function fallbackToSms(string $mobile, string $message, string $reason): bool
    {
        if (get_option('rtoflow_wa_sms_fallback', '1') !== '1') {
            return false;
        }
        error_log("RTOFLOW WhatsApp: falling back to SMS ({$reason}) for " . self::maskMobile($mobile ?: 'unknown'));
        if (!class_exists('RTOFLOW_SMS')) return false;
        return \RTOFLOW_SMS::send($mobile, $message);
    }

    private static function maskMobile(string $mobile): string
    {
        return strlen($mobile) >= 4 ? ('XXXXXX' . substr($mobile, -4)) : 'XXXXXX';
    }

    /**
     * Send a pre-approved template message.
     * Template must be approved in Meta Business Manager.
     *
     * NOTE (Phase 4, item 7 scope): no SMS fallback here, deliberately —
     * unlike send()'s plain text, $components is Meta's structured template
     * parameter format with no generic, reliable way to flatten it into a
     * readable SMS body. Callers that need guaranteed cross-channel delivery
     * should use send() with a pre-rendered message string instead.
     */
    public static function sendTemplate(string $mobile, string $templateName, array $components = [], string $language = 'en'): bool
    {
        if (!self::isEnabled()) return false;

        $mobile = self::normaliseMobile($mobile);
        if (!$mobile) return false;

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => '91' . $mobile,
            'type'              => 'template',
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => $language],
                'components' => $components,
            ],
        ];

        return self::call($payload);
    }

    // ── Webhook verification ───────────────────────────────────────────────

    /**
     * Verify webhook from Meta (GET request challenge).
     * Call in your webhook endpoint and return the hub.challenge value.
     */
    public static function verifyWebhook(): ?string
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('webhook_whatsapp')) {
            return null;
        }
        $mode      = $_GET['hub_mode']      ?? '';
        $token     = $_GET['hub_verify_token'] ?? '';
        $challenge = $_GET['hub_challenge'] ?? '';
        $expected  = \RTOFLOW\Config\Env::string('WHATSAPP_VERIFY_TOKEN', '');

        if ($mode === 'subscribe' && hash_equals($expected, $token)) {
            return $challenge;
        }
        return null;
    }

    // ── Handle inbound webhook ────────────────────────────────────────────

    public static function parseInbound(string $rawBody): ?array
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('webhook_whatsapp')) {
            return null;
        }
        $data = json_decode($rawBody, true);
        if (!$data) return null;

        $messages = $data['entry'][0]['changes'][0]['value']['messages'] ?? [];
        if (empty($messages)) return null;

        $msg = $messages[0];
        return [
            'from'    => $msg['from'] ?? '',   // Sender mobile (with country code)
            'text'    => $msg['text']['body'] ?? ($msg['button']['text'] ?? ''),
            'type'    => $msg['type'] ?? 'text',
            'id'      => $msg['id'] ?? '',
            'timestamp' => $msg['timestamp'] ?? '',
        ];
    }

    // ── Status ────────────────────────────────────────────────────────────

    public static function isEnabled(): bool
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('whatsapp_notifications')) {
            return false;
        }
        $enabled = \RTOFLOW\Config\Env::bool('WHATSAPP_ENABLED', false)
            || get_option('rtoflow_wa_enabled', '0') === '1';
        return $enabled && !empty(self::getToken()) && !empty(self::getPhoneId());
    }

    private static function getToken(): string
    {
        // FIX P0-8: WhatsApp access token is now stored encrypted
        // (rtoflow_wa_token_enc), same pattern as the SMS API key.
        $env = \RTOFLOW\Config\Env::string('WHATSAPP_TOKEN', '');
        if ($env !== '') return $env;

        $encrypted = (string)get_option('rtoflow_wa_token_enc', '');
        if ($encrypted !== '') {
            return \RTOFLOW\Security\Encryption::decryptSafe($encrypted);
        }
        return (string)get_option('rtoflow_wa_token', '');
    }

    private static function getPhoneId(): string
    {
        return \RTOFLOW\Config\Env::string('WHATSAPP_PHONE_ID', '')
            ?: (string)get_option('rtoflow_wa_phone_id', '');
    }

    // ── Internal ──────────────────────────────────────────────────────────

    private static function call(array $payload): bool
    {
        $phoneId = self::getPhoneId();
        $token   = self::getToken();
        $url     = self::API_BASE . self::API_VERSION . '/' . $phoneId . '/messages';

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            error_log('RTOFLOW WhatsApp: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['messages'][0]['id'])) {
            return true;
        }

        error_log('RTOFLOW WhatsApp: Failed — HTTP ' . $code . ' — ' . wp_json_encode($body));
        return false;
    }

    private static function normaliseMobile(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) $digits = substr($digits, 2);
        if (strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits)) return $digits;
        return '';
    }
}
