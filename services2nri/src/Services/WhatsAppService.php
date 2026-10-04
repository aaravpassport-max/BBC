<?php
namespace S2NRI\Services;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsAppService — CallMeBot API integration for WhatsApp notifications (M-14).
 *
 * TRACE: send(number, message) → get apikey from settings →
 *        build CallMeBot URL → wp_remote_get with 15s timeout →
 *        return {success, message, response_code}.
 * PRECONDITIONS: whatsapp_callmebot_apikey and whatsapp_notify_numbers set
 *                in s2nri_settings — FIXED: this docblock previously named
 *                a different key ("platform_whatsapp_apikey") that the code
 *                never actually read; traced and corrected to match the real
 *                key used below. Both settings previously had NO admin UI
 *                path to configure them at all — fixed separately in
 *                src/pages/admin/index.tsx (Settings page).
 *                Number must be registered with CallMeBot first (user sends
 *                "I allow callmebot to send me messages" to their WhatsApp).
 * POSTCONDITIONS: WhatsApp message delivered or error returned.
 * EDGE CASES: empty API key → error. Network timeout → error.
 *             CallMeBot rate limit (3 req/min) → 503 response from API.
 */
class WhatsAppService {

    private const CALLMEBOT_URL = 'https://api.callmebot.com/whatsapp.php';
    private const TIMEOUT       = 15;

    /**
     * Send a WhatsApp message via CallMeBot API.
     *
     * @param  string $number  E.164 or international format (e.g. +919876543210)
     * @param  string $message Plain text message (max ~4096 chars per WA limit)
     * @return array  { success: bool, message: string, status_code: int|null }
     */
    public static function send( string $number, string $message ): array {
        $api_key = Setting::get('whatsapp_callmebot_apikey', '');

        if ( empty($api_key) ) {
            return [
                'success'     => false,
                'message'     => 'WhatsApp API key not configured. Go to Settings → WhatsApp to set your CallMeBot API key.',
                'status_code' => null,
            ];
        }

        // Sanitize number: remove spaces, keep + and digits
        $number = preg_replace('/[^\+\d]/', '', $number);
        if ( ! $number ) {
            return ['success' => false, 'message' => 'Invalid phone number.', 'status_code' => null];
        }

        $url = add_query_arg([
            'phone'   => rawurlencode($number),
            'text'    => rawurlencode($message),
            'apikey'  => rawurlencode($api_key),
            'v'       => '2',
        ], self::CALLMEBOT_URL);

        $response = wp_remote_get($url, [
            'timeout'   => self::TIMEOUT,
            'sslverify' => true,
        ]);

        if ( is_wp_error($response) ) {
            return [
                'success'     => false,
                'message'     => 'Network error: ' . $response->get_error_message(),
                'status_code' => null,
            ];
        }

        $code    = (int) wp_remote_retrieve_response_code($response);
        $body    = wp_remote_retrieve_body($response);
        $success = ( $code >= 200 && $code < 300 );

        // Log the send attempt
        error_log("[S2NRI WhatsApp] to:{$number} code:{$code} body:{$body}");

        return [
            'success'     => $success,
            'message'     => $success ? 'Message sent successfully.' : "CallMeBot returned HTTP {$code}. Check your API key and number registration.",
            'status_code' => $code,
            'response'    => $success ? null : $body,
        ];
    }

    /**
     * Send notification to all configured admin/staff WhatsApp numbers.
     * Numbers are stored in the 'whatsapp_notify_numbers' setting as newline-separated list.
     *
     * TRACE: get whatsapp_notify_numbers setting → split by \n →
     *        iterate → call send() for each → log results.
     * EDGE CASES: empty numbers list → no-op, returns empty array.
     *             Individual send failures → logged, not thrown.
     */
    public static function notifyAdmins( string $message ): array {
        $numbers_raw = Setting::get('whatsapp_notify_numbers', '');
        if ( ! $numbers_raw ) return [];

        $numbers = array_filter( array_map('trim', explode("\n", $numbers_raw)) );
        $results = [];
        foreach ($numbers as $number) {
            if ($number) {
                $results[$number] = self::send($number, $message);
            }
        }
        return $results;
    }

    /**
     * Build a WhatsApp notification message from a template string.
     * Variables: {PLATFORM}, {BOOKING_REF}, {CUSTOMER_NAME}, {SERVICE}, {STATUS}
     *
     * @param  string $template Template string with {VAR} placeholders
     * @param  array  $data     Key => value map of variable substitutions
     * @return string Rendered message
     */
    public static function renderTemplate( string $template, array $data ): string {
        $vars = [
            '{PLATFORM}'      => Setting::get('platform_name', 'Services2NRI'),
            '{BOOKING_REF}'   => $data['booking_ref']   ?? '',
            '{CUSTOMER_NAME}' => $data['customer_name'] ?? '',
            '{SERVICE}'       => $data['service_name']  ?? '',
            '{STATUS}'        => isset($data['status']) ? ucwords(str_replace('_',' ',$data['status'])) : '',
            '{AMOUNT}'        => isset($data['amount'])  ? '₹' . number_format((float)$data['amount'], 2) : '',
            '{DATE}'          => date('d M Y'),
        ];
        return str_replace(array_keys($vars), array_values($vars), $template);
    }

    /**
     * Fire a booking event notification to all admin numbers.
     * Uses the event-specific template from settings if configured.
     *
     * @param  string $event_type   E.g. 'booking_submitted', 'status_changed', 'payment_received'
     * @param  array  $booking_data Booking data for template variables
     */
    public static function notifyBookingEvent( string $event_type, array $booking_data ): void {
        $template_key = 'whatsapp_template_' . $event_type;
        $template     = Setting::get($template_key, '');

        // Default templates for common events
        if ( ! $template ) {
            $template = match($event_type) {
                'booking_submitted'  => "📋 New request {BOOKING_REF} from {CUSTOMER_NAME} for {SERVICE}. Login to review.",
                'status_changed'     => "🔄 Status update: {BOOKING_REF} is now *{STATUS}*. Customer: {CUSTOMER_NAME}.",
                'payment_received'   => "💰 Payment received for {BOOKING_REF} ({AMOUNT}). Customer: {CUSTOMER_NAME}.",
                'quote_approved'     => "✅ Quote approved for {BOOKING_REF} ({AMOUNT}). {CUSTOMER_NAME} is ready to proceed.",
                default              => "Update on {BOOKING_REF} ({STATUS}) — {PLATFORM}",
            };
        }

        if ( ! $template ) return;

        $message = self::renderTemplate($template, $booking_data);
        self::notifyAdmins($message);
    }
}
