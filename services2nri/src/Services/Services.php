<?php
namespace S2NRI\Services;

defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════════════════
// CacheService — WordPress transient wrapper
// ══════════════════════════════════════════════════════════════════════════════

/**
 * TRACE: remember(key, ttl, fn) → check transient → on miss call fn, store result, return.
 *        bustPattern(prefix) → DELETE all transients matching s2nri_{prefix}%.
 *        Preconditions: wp_options table writable.
 *        Postconditions: Cached value stored/returned. Miss falls back to callback.
 */
class CacheService {

    private const PREFIX      = 's2nri_';
    private const MAX_KEY_LEN = 172;

    public static function get( string $key ): mixed {
        $val = get_transient( self::key( $key ) );
        return $val !== false ? $val : null;
    }

    public static function set( string $key, mixed $value, int $ttl = 120 ): void {
        set_transient( self::key( $key ), $value, $ttl );
    }

    public static function delete( string $key ): void {
        delete_transient( self::key( $key ) );
    }

    public static function bustPattern( string $pattern ): void {
        global $wpdb;
        // FIXED: previously only deleted '_transient_' rows, never their
        // '_transient_timeout_' companion rows — WordPress creates one of
        // these for every transient set with an expiry (every call to
        // self::set() here uses a $ttl), so every single cache-bust
        // (settings saves, category/city edits, etc. — a frequent
        // operation) left a permanently orphaned row in wp_options.
        // Not a functional bug (get_transient() correctly returns false
        // once the value row is gone, regardless of an orphaned timeout
        // row) but a real, quietly-accumulating table-bloat issue over a
        // live business's lifetime. Now cleans up both.
        $like_value   = $wpdb->esc_like( '_transient_' . self::PREFIX . $pattern ) . '%';
        $like_timeout = $wpdb->esc_like( '_transient_timeout_' . self::PREFIX . $pattern ) . '%';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like_value, $like_timeout
        ) );
    }

    public static function remember( string $key, int $ttl, callable $callback ): mixed {
        $cached = self::get( $key );
        if ( $cached !== null ) return $cached;
        $value = $callback();
        if ( $value !== null ) self::set( $key, $value, $ttl );
        return $value;
    }

    private static function key( string $key ): string {
        $k = self::PREFIX . $key;
        if ( strlen( $k ) > self::MAX_KEY_LEN ) {
            $k = self::PREFIX . 'h_' . md5( $key );
        }
        return $k;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// OtpService — email OTP generation, storage, verification
// ══════════════════════════════════════════════════════════════════════════════

/**
 * TRACE: send(email, purpose) → rate-limit check → generate 6-digit OTP → hash → insert s2nri_otps → send email.
 *        verify(email, otp, purpose) → find valid row → check hash → mark used → return result.
 *        Rate limit: 3 per 10 min per email per purpose.
 *        OTP expiry: 10 minutes. Max attempts: 5.
 *        Preconditions: s2nri_otps table exists, wp_mail available.
 *        Postconditions: OTP row inserted / marked used. Email sent.
 *        Edge cases: expired OTP, wrong OTP, max attempts exceeded, used OTP.
 */
class OtpService {

    const EXPIRY_SECONDS   = 600;   // 10 minutes
    const MAX_ATTEMPTS     = 5;
    const RATE_LIMIT_COUNT = 3;
    const RATE_LIMIT_SECS  = 600;   // 10 minutes

    public static function send( string $email, string $purpose ): void {
        global $wpdb;
        $t     = $wpdb->prefix . 's2nri_otps';
        $email = strtolower( trim( $email ) );

        // Rate-limit check
        $recent = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t}
             WHERE identifier = %s AND purpose = %s
               AND created_at > DATE_SUB(NOW(), INTERVAL %d SECOND)",
            $email, $purpose, self::RATE_LIMIT_SECS
        ) );

        if ( $recent >= self::RATE_LIMIT_COUNT ) {
            throw new \Exception( 'Too many OTP requests. Please wait 10 minutes before trying again.' );
        }

        // Generate and hash
        $otp      = str_pad( (string) random_int( 100000, 999999 ), 6, '0', STR_PAD_LEFT );
        $otp_hash = wp_hash_password( $otp );
        $expires  = gmdate( 'Y-m-d H:i:s', time() + self::EXPIRY_SECONDS );

        $otp_insert = $wpdb->insert( $t, [
            'identifier' => $email,
            'otp_hash'   => $otp_hash,
            'purpose'    => $purpose,
            'attempts'   => 0,
            'expires_at' => $expires,
            'created_at' => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): the email send below was
        // previously unconditional — a failed insert here meant the user
        // received a valid-looking "here is your OTP" email for a code
        // that was never stored, which verify() (below) can never match
        // against anything. Fail loudly instead of sending an email that
        // can never succeed.
        if ( $otp_insert === false ) {
            error_log( '[S2NRI] OTP insert failed for ' . $email . ' (' . $purpose . '): ' . $wpdb->last_error );
            throw new \Exception( 'Failed to generate verification code. Please try again.' );
        }

        // Send email
        $labels = [
            'login'          => 'Login',
            'register'       => 'Registration',
            'password_reset' => 'Password Reset',
        ];
        $label     = $labels[ $purpose ] ?? 'Verification';
        $site_name = \S2NRI\Models\Setting::get( 'platform_name', 'Services2NRI' );

        $subject = "{$site_name} — Your {$label} OTP: {$otp}";
        $body    = EmailService::otpEmailBody( $otp, $label, $site_name );

        add_filter( 'wp_mail_content_type', fn() => 'text/html' );
        wp_mail( $email, $subject, $body );
        remove_all_filters( 'wp_mail_content_type' );
    }

    public static function verify( string $email, string $otp, string $purpose ): array {
        global $wpdb;
        $t     = $wpdb->prefix . 's2nri_otps';
        $email = strtolower( trim( $email ) );

        // Find the most recent unused, unexpired OTP
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t}
             WHERE identifier = %s AND purpose = %s AND used_at IS NULL
               AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1",
            $email, $purpose
        ), ARRAY_A );

        if ( ! $row ) {
            return [ 'success' => false, 'message' => 'OTP expired or not found. Please request a new one.' ];
        }

        // Check attempts
        if ( (int) $row['attempts'] >= self::MAX_ATTEMPTS ) {
            return [ 'success' => false, 'message' => 'Too many incorrect attempts. Please request a new OTP.' ];
        }

        // Increment attempts
        // FIXED: previously computed the new value in PHP from the
        // already-fetched row ($row['attempts'] + 1) then wrote it back —
        // a read-then-write race under parallel requests could under-
        // count attempts (letting an attacker make more than
        // MAX_ATTEMPTS guesses by parallelizing). Using an atomic SQL
        // increment instead, which MySQL applies against the column's
        // own current value at write time, not a possibly-stale PHP one.
        $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET attempts = attempts + 1 WHERE id = %d", $row['id'] ) );

        // Verify hash
        if ( ! wp_check_password( $otp, $row['otp_hash'] ) ) {
            $remaining = self::MAX_ATTEMPTS - (int) $row['attempts'] - 1;
            return [ 'success' => false, 'message' => "Incorrect OTP. {$remaining} attempts remaining." ];
        }

        // Mark used
        // FIXED: previously a plain update with no "WHERE used_at IS
        // NULL" condition — the same single-use-enforcement gap already
        // found and fixed for magic links elsewhere this session. Two
        // simultaneous requests verifying the same correct OTP (double-
        // click, replay, two tabs) could both pass the SELECT above
        // (neither had marked it used yet) and both pass the hash check,
        // both returning success:true — the same OTP usable twice.
        // Atomic conditional update: only ONE of two simultaneous
        // attempts can ever win, since the first to run sets used_at
        // non-null, making the second's WHERE clause match zero rows.
        $claimed = $wpdb->update( $t, [ 'used_at' => current_time( 'mysql' ) ], [ 'id' => $row['id'], 'used_at' => null ] );
        if ( ! $claimed ) {
            // Someone else's simultaneous request already claimed this
            // exact OTP a moment earlier — do not also report success.
            return [ 'success' => false, 'message' => 'This OTP was already used. Please request a new one.' ];
        }
        return [ 'success' => true, 'message' => 'OTP verified.' ];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// NotificationService — in-app + email notifications
// ══════════════════════════════════════════════════════════════════════════════

/**
 * TRACE: notifyCustomer(wp_id, type, data) → INSERT s2nri_notifications.
 *        notifyAdmins(type, data) → find all staff WP users → INSERT notifications for each.
 *        sendWelcomeEmail → EmailService::send().
 *        Preconditions: s2nri_notifications, s2nri_staff tables exist.
 *        Postconditions: Notification rows inserted. Emails sent via wp_mail.
 */
class NotificationService {

    public static function notifyCustomer( int $wp_user_id, string $type, array $data ): void {
        global $wpdb;
        // CHECKED (was previously unchecked): this is the shared primitive
        // underlying EVERY notification path in the codebase — booking
        // events, payment events, admin broadcasts, quote reminders, etc.
        // all funnel through here. Fixing it here benefits every caller
        // at once. Kept the void signature (every existing caller across
        // the codebase expects void) — logs loudly on failure instead of
        // changing the contract this late.
        $result = $wpdb->insert( $wpdb->prefix . 's2nri_notifications', [
            'user_id'    => $wp_user_id,
            'type'       => $type,
            'title'      => sanitize_text_field( $data['title'] ?? '' ),
            'body'       => sanitize_textarea_field( $data['body'] ?? '' ),
            'booking_id' => isset( $data['booking_id'] ) ? (int) $data['booking_id'] : null,
            'is_read'    => 0,
            'created_at' => current_time( 'mysql' ),
        ] );
        if ( $result === false ) {
            error_log( "[S2NRI] notifyCustomer() insert failed for user {$wp_user_id}, type={$type}: " . $wpdb->last_error );
        }
    }

    public static function notifyAdmins( string $type, array $data ): void {
        global $wpdb;
        $staff_users = $wpdb->get_col(
            "SELECT wp_user_id FROM {$wpdb->prefix}s2nri_staff WHERE is_active = 1"
        );
        // Also notify WP admins
        $wp_admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 10 ] );
        $all       = array_unique( array_merge( array_map( 'intval', $staff_users ), $wp_admins ) );

        foreach ( $all as $uid ) {
            self::notifyCustomer( (int) $uid, $type, $data );
        }
    }

    public static function sendWelcomeEmail( string $email, string $name ): void {
        $site_name = \S2NRI\Models\Setting::get( 'platform_name', 'Services2NRI' );
        $subject   = "Welcome to {$site_name}, {$name}!";
        $body      = EmailService::welcomeEmailBody( $name, $email, $site_name );

        add_filter( 'wp_mail_content_type', fn() => 'text/html' );
        wp_mail( $email, $subject, $body );
        remove_all_filters( 'wp_mail_content_type' );
    }



}

class EmailService {

    private static function wrapper( string $content, string $site_name, string $primary_color ): string {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">
      <tr><td style="background:{$primary_color};padding:24px 32px;">
        <h1 style="margin:0;color:#fff;font-size:22px;">{$site_name}</h1>
      </td></tr>
      <tr><td style="padding:32px;">{$content}</td></tr>
      <tr><td style="background:#f8f9fa;padding:16px 32px;text-align:center;color:#666;font-size:12px;">
        © {$site_name} · <a href="{home_url}" style="color:{$primary_color};text-decoration:none;">Visit Website</a>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    public static function otpEmailBody( string $otp, string $label, string $site_name ): string {
        $primary = \S2NRI\Models\Setting::get( 'primary_color', '#4A6FA5' );
        $content = <<<HTML
<h2 style="color:#1a1a1a;margin-top:0;">Your {$label} OTP</h2>
<p style="color:#555;font-size:16px;">Use the following OTP to complete your {$label}. This code expires in <strong>10 minutes</strong>.</p>
<div style="background:#f0f4ff;border:2px dashed {$primary};border-radius:8px;padding:24px;text-align:center;margin:24px 0;">
  <span style="font-size:42px;font-weight:bold;color:{$primary};letter-spacing:10px;">{$otp}</span>
</div>
<p style="color:#888;font-size:13px;">If you did not request this, please ignore this email. Do not share this code with anyone.</p>
HTML;
        return self::wrapper( $content, $site_name, $primary );
    }

    public static function welcomeEmailBody( string $name, string $email, string $site_name ): string {
        $primary  = \S2NRI\Models\Setting::get( 'primary_color', '#4A6FA5' );
        $site_url = home_url( '/dashboard' );
        $content  = <<<HTML
<h2 style="color:#1a1a1a;margin-top:0;">Welcome, {$name}! 🎉</h2>
<p style="color:#555;font-size:16px;">Your {$site_name} account has been created successfully.</p>
<p style="color:#555;">You can now submit service requests, track your bookings, and communicate with our team — all from your dashboard.</p>
<div style="margin:24px 0;">
  <a href="{$site_url}" style="background:{$primary};color:#fff;padding:14px 28px;border-radius:6px;text-decoration:none;font-size:16px;font-weight:bold;">Go to Dashboard →</a>
</div>
<p style="color:#888;font-size:13px;">Your registered email: {$email}</p>
<p style="color:#888;font-size:13px;">If you have any questions, reply to this email or use the Support section in your dashboard.</p>
HTML;
        return self::wrapper( $content, $site_name, $primary );
    }

    public static function sendQuoteEmail( int $wp_user_id, int $booking_id, float $amount, string $valid_until ): void {
        $wp_user = get_user_by( 'id', $wp_user_id );
        if ( ! $wp_user ) return;

        global $wpdb;
        $p = $wpdb->prefix;

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.booking_ref, b.quoted_amount, b.shipping_type, b.delivery_working_days, b.due_date,
                    b.secondary_status, s.name AS service_name
             FROM {$p}s2nri_bookings b JOIN {$p}s2nri_services s ON s.id=b.service_id
             WHERE b.id=%d LIMIT 1",
            $booking_id
        ), ARRAY_A );

        $ref        = $booking['booking_ref'] ?? '';
        $site_name  = \S2NRI\Models\Setting::get( 'platform_name', 'Services2NRI' );
        $primary    = \S2NRI\Models\Setting::get( 'primary_color', '#4A6FA5' );
        $portal_url = home_url( '/dashboard/bookings/' . $booking_id );
        $fmt_amount = '₹' . number_format( $amount, 2 );
        $fmt_valid  = date( 'j M Y', strtotime( $valid_until ) );

        // Get line item breakdown from latest quote
        $quote       = $wpdb->get_row( $wpdb->prepare(
            "SELECT line_items, breakdown FROM {$p}s2nri_quotes WHERE booking_id=%d ORDER BY id DESC LIMIT 1", $booking_id
        ), ARRAY_A );
        $line_items  = json_decode( $quote['line_items'] ?? $quote['breakdown'] ?? '[]', true ) ?: [];
        $svc_charges = 0.0; $ship_charges = 0.0;
        foreach ( $line_items as $item ) {
            if ( ($item['type']??'') === 'shipping' ) $ship_charges = (float)($item['amount']??0);
            elseif ( ($item['type']??'') === 'service' ) $svc_charges = (float)($item['amount']??0);
        }
        if ( $svc_charges === 0.0 ) $svc_charges = $amount - $ship_charges;

        $delivery_block = '';
        if ( $booking['due_date'] ?? false ) {
            $days   = $booking['delivery_working_days'] ?? '';
            $note   = $days ? " ({$days} working days — excl. Sat, Sun &amp; Govt Holidays)" : '';
            $delivery_block = '<table width="100%" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin:0 0 20px"><tr><td style="padding:14px 18px">
              <span style="font-size:13px;font-weight:600;color:#1d4ed8">⏱ Estimated Delivery: ' . esc_html( date('d M Y',strtotime($booking['due_date'])).$note ) . '</span>
            </td></tr></table>';
        }

        $vars = [
            '{CUSTOMER_NAME}'    => $wp_user->display_name,
            '{BOOKING_REF}'      => $ref,
            '{SERVICE_NAME}'     => $booking['service_name'] ?? '',
            '{SERVICE_CHARGES}'  => '₹' . number_format( $svc_charges, 2 ),
            '{SHIPPING_CHARGES}' => '₹' . number_format( $ship_charges, 2 ),
            '{SHIPPING_TYPE}'    => ucfirst( $booking['shipping_type'] ?? 'domestic' ),
            '{TOTAL_AMOUNT}'     => $fmt_amount,
            '{QUOTE_VALID_UNTIL}'=> $fmt_valid,
            '{SECONDARY_STATUS}' => '',
            '{TIMELINE_BLOCK}'   => $delivery_block,
            '{TC_BLOCK}'         => '',
            '{PLATFORM_NAME}'    => $site_name,
            '{DASHBOARD_URL}'    => $portal_url,
        ];

        // Try template system first; fall back to inline HTML
        $tmpl = $wpdb->get_row( "SELECT subject, body FROM {$p}s2nri_email_templates WHERE slug='quote_sent' LIMIT 1", ARRAY_A );
        if ( $tmpl ) {
            $subject = str_replace( array_keys($vars), array_values($vars), $tmpl['subject'] );
            $body    = str_replace( array_keys($vars), array_values($vars), $tmpl['body'] );
        } else {
            $subject = "{$site_name} — Quote for {$ref}: {$fmt_amount}";
            $body    = self::wrapper(
                "<h2 style='color:#1a1a1a;margin-top:0'>Quote Ready for Booking {$ref}</h2>
                 <p>Amount: <strong>{$fmt_amount}</strong> | Valid until: {$fmt_valid}</p>
                 {$delivery_block}
                 <a href='{$portal_url}' style='background:{$primary};color:#fff;padding:14px 28px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:bold'>Review Quote →</a>",
                $site_name, $primary
            );
        }

        add_filter( 'wp_mail_content_type', fn() => 'text/html' );
        $sent = wp_mail( $wp_user->user_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8'] );
        remove_all_filters( 'wp_mail_content_type' );

        self::log( $booking_id, $wp_user->user_email, $subject, 'quote_sent', $sent );
    }

    public static function sendStatusUpdate( int $wp_user_id, int $booking_id, string $status, string $ref ): void {
        global $wpdb;
        $wp_user = get_user_by( 'id', $wp_user_id );
        if ( ! $wp_user ) return;

        $site_name  = \S2NRI\Models\Setting::get( 'platform_name', 'Services2NRI' );
        $primary    = \S2NRI\Models\Setting::get( 'primary_color', '#4A6FA5' );
        $portal_url = home_url( '/dashboard/bookings/' . $booking_id );
        $label      = ucfirst( str_replace( '_', ' ', $status ) );

        // Fetch booking for template variables (secondary_status, delivery_info)
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.secondary_status, b.due_date, b.delivery_working_days
             FROM {$wpdb->prefix}s2nri_bookings b WHERE b.id=%d LIMIT 1",
            $booking_id
        ), ARRAY_A );

        $sec_status_html = '';
        if ( $booking && $booking['secondary_status'] ) {
            $sec_status_html = '<p style="font-size:13px;color:#6b7280;font-style:italic;margin:4px 0 0">' . esc_html($booking['secondary_status']) . '</p>';
        }
        $delivery_block = '';
        if ( $booking && $booking['due_date'] ) {
            $days  = $booking['delivery_working_days'] ?? '';
            $note  = $days ? " ({$days} working days — excl. Sat, Sun & Govt Holidays)" : '';
            $delivery_block = '<table width="100%" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin:0 0 20px"><tr><td style="padding:14px 18px">
              <span style="font-size:13px;font-weight:600;color:#1d4ed8">⏱ Estimated Delivery: ' . esc_html( date('d M Y', strtotime($booking['due_date'])) . $note ) . '</span>
            </td></tr></table>';
        }

        $vars = [
            '{CUSTOMER_NAME}'    => $wp_user->display_name,
            '{BOOKING_REF}'      => $ref,
            '{STATUS}'           => $label,
            '{SECONDARY_STATUS}' => $sec_status_html,
            '{TIMELINE}'         => $booking['delivery_working_days'] ? $booking['delivery_working_days'] . ' working days' : '',
            '{DELIVERY_DATE}'    => $booking['due_date'] ? date('d M Y', strtotime($booking['due_date'])) : '',
            '{PLATFORM_NAME}'    => $site_name,
            '{DASHBOARD_URL}'    => $portal_url,
            '{TIMELINE_BLOCK}'   => $delivery_block,
            '{TC_BLOCK}'         => '',
        ];

        // Try template system first
        $tmpl = $wpdb->get_row(
            "SELECT subject, body FROM {$wpdb->prefix}s2nri_email_templates WHERE slug='status_update' LIMIT 1",
            ARRAY_A
        );

        if ( $tmpl ) {
            $subject = str_replace( array_keys($vars), array_values($vars), $tmpl['subject'] );
            $body    = str_replace( array_keys($vars), array_values($vars), $tmpl['body'] );
        } else {
            // Fallback inline template
            $subject = "{$site_name} — Booking {$ref} is now {$label}";
            $content = "<h2 style='color:#1a1a1a;margin-top:0'>Update on Booking {$ref}</h2>
                <div style='background:#f0f4ff;border-left:4px solid {$primary};padding:16px;border-radius:4px;margin:16px 0'>
                  <strong>Status: {$label}</strong>" . ( $sec_status_html ? "<br>{$sec_status_html}" : '' ) . "
                </div>{$delivery_block}
                <p><a href='{$portal_url}' style='background:{$primary};color:#fff;padding:14px 28px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:bold'>View Booking →</a></p>";
            $body = self::wrapper( $content, $site_name, $primary );
        }

        add_filter( 'wp_mail_content_type', fn() => 'text/html' );
        $sent = wp_mail( $wp_user->user_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8'] );
        remove_all_filters( 'wp_mail_content_type' );
        self::log( $booking_id, $wp_user->user_email, $subject, "status_{$status}", $sent );
    }

    private static function log( ?int $booking_id, string $to, string $subject, string $type, bool $sent ): void {
        global $wpdb;
        if ( $wpdb->insert( $wpdb->prefix . 's2nri_email_log', [
            'booking_id' => $booking_id,
            'to_email'   => $to,
            'subject'    => $subject,
            'type'       => $type,
            'status'     => $sent ? 'sent' : 'failed',
            'created_at' => current_time( 'mysql' ),
        ] ) === false ) {
            error_log( '[S2NRI] email_log insert failed for ' . $to . ' (' . $type . '): ' . $wpdb->last_error );
        }
    }

    public function sendBookingConfirmation( array $booking, string $customer_email, string $customer_name ): void {
        $settings  = \S2NRI\Models\Setting::getPublic();
        $site_name = $settings['platform_name']   ?? 'Services2NRI';
        $primary   = $settings['primary_color']   ?? '#4A6FA5';
        $wa        = $settings['platform_whatsapp'] ?? '';
        $wa_link   = $wa ? "https://wa.me/{$wa}" : '';

        $subject = "[{$site_name}] Booking Confirmed — {$booking['booking_ref']}";

        // Pre-compute optional blocks before heredoc (ternary not allowed in heredoc interpolation)
        $wa_block        = $wa_link ? "<p style='text-align:center;margin:0 0 24px'><a href='{$wa_link}' style='color:#25d366;font-weight:600;text-decoration:none'>&#x1F4AC; Chat with us on WhatsApp</a></p>" : '';
        $booking_ref     = $booking['booking_ref'];
        $service_name    = $booking['service_name'];
        $dashboard_url   = $booking['dashboard_url'];

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F5F7FA;font-family:system-ui,-apple-system,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F5F7FA;padding:32px 16px">
<tr><td>
  <table width="600" align="center" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:100%">
    <tr><td style="background:{$primary};padding:24px 32px;text-align:center">
      <h1 style="color:#fff;margin:0;font-size:22px;font-weight:800">{$site_name}</h1>
    </td></tr>
    <tr><td style="padding:32px">
      <h2 style="color:#1E2D40;font-size:20px;margin:0 0 8px">Booking Confirmed &#x2705;</h2>
      <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 24px">
        Dear {$customer_name},<br><br>
        Your service request has been received and is now under review. Our expert team will send you a detailed, itemised quote within <strong>24 hours</strong>.
      </p>
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#F5F7FA;border-radius:8px;padding:16px;margin-bottom:24px">
        <tr><td style="padding:6px 0"><span style="color:#888;font-size:13px">Booking Reference:</span><strong style="float:right;color:#1E2D40">{$booking_ref}</strong></td></tr>
        <tr><td style="padding:6px 0;border-top:1px solid #e8eaf0"><span style="color:#888;font-size:13px">Service:</span><strong style="float:right;color:#1E2D40">{$service_name}</strong></td></tr>
        <tr><td style="padding:6px 0;border-top:1px solid #e8eaf0"><span style="color:#888;font-size:13px">Status:</span><strong style="float:right;color:{$primary}">Under Review</strong></td></tr>
      </table>
      <p style="text-align:center;margin:0 0 16px">
        <a href="{$dashboard_url}" style="background:{$primary};color:#fff;padding:13px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;display:inline-block">Track Your Booking &#x2192;</a>
      </p>
      {$wa_block}
      <p style="color:#9ca3af;font-size:13px;text-align:center;margin:0">
        You are receiving this because you registered on {$site_name}.<br>
        Please do not reply to this email. Use WhatsApp or the dashboard to reach us.
      </p>
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
HTML;

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ( $settings['email_from_name'] ?? $site_name ) . ' <' . ( $settings['email_from'] ?? get_option('admin_email') ) . '>',
        ];
        wp_mail( $customer_email, $subject, $html, $headers );
    }

    /**
     * sendPaymentConfirmation — HTML email when admin verifies a payment.
     * TRACE: called from PaymentController::verify() after status='verified'.
     */
    public function sendPaymentConfirmation( int $wp_id, array $payment, string $booking_ref, string $service_name ): void {
        $wp_user   = get_user_by( 'ID', $wp_id );
        if ( ! $wp_user ) return;

        $settings  = \S2NRI\Models\Setting::getPublic();
        $site_name = $settings['platform_name'] ?? 'Services2NRI';
        $primary   = $settings['primary_color'] ?? '#4A6FA5';
        $amount    = number_format( (float) $payment['amount'], 2 );
        $currency  = $payment['currency'] ?? 'INR';
        $ref       = $payment['transaction_ref'] ?? $payment['utr_number'] ?? '—';
        $customer_name = $wp_user->display_name;
        $dashboard_url = home_url( '/dashboard/bookings' );

        $subject = "[{$site_name}] Payment Received — {$booking_ref}";

        $amount_html  = esc_html( "{$currency} {$amount}" );
        $ref_html     = esc_html( $ref );
        $name_html    = esc_html( $customer_name );
        $svc_html     = esc_html( $service_name );
        $bref_html    = esc_html( $booking_ref );

        $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>"
            . "<body style='margin:0;padding:0;background:#F5F7FA;font-family:system-ui,Arial,sans-serif'>"
            . "<table width='100%' cellpadding='0' cellspacing='0' style='background:#F5F7FA;padding:32px 16px'><tr><td>"
            . "<table width='600' align='center' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;max-width:100%'>"
            . "<tr><td style='background:{$primary};padding:22px 32px;text-align:center'>"
            . "<h1 style='color:#fff;margin:0;font-size:20px;font-weight:800'>{$site_name}</h1></td></tr>"
            . "<tr><td style='padding:28px 32px'>"
            . "<h2 style='color:#15803d;font-size:20px;margin:0 0 8px'>Payment Confirmed ✅</h2>"
            . "<p style='color:#555;font-size:15px;line-height:1.7;margin:0 0 20px'>Dear {$name_html},<br><br>Your payment has been received and verified. We are now actively processing your service request.</p>"
            . "<table width='100%' cellpadding='0' cellspacing='0' style='background:#F5F7FA;border-radius:8px;padding:16px;margin-bottom:20px'>"
            . "<tr><td style='padding:6px 0;font-size:13px;color:#888'>Booking Reference:</td><td style='font-weight:700;color:#1E2D40;text-align:right'>{$bref_html}</td></tr>"
            . "<tr><td style='padding:6px 0;border-top:1px solid #e8eaf0;font-size:13px;color:#888'>Service:</td><td style='font-weight:700;color:#1E2D40;text-align:right'>{$svc_html}</td></tr>"
            . "<tr><td style='padding:6px 0;border-top:1px solid #e8eaf0;font-size:13px;color:#888'>Amount Received:</td><td style='font-weight:700;color:#15803d;text-align:right'>{$amount_html}</td></tr>"
            . "<tr><td style='padding:6px 0;border-top:1px solid #e8eaf0;font-size:13px;color:#888'>Reference:</td><td style='font-weight:700;text-align:right'>{$ref_html}</td></tr>"
            . "<tr><td style='padding:6px 0;border-top:1px solid #e8eaf0;font-size:13px;color:#888'>Status:</td><td style='font-weight:700;color:{$primary};text-align:right'>In Progress</td></tr>"
            . "</table>"
            . "<p style='text-align:center;margin:0 0 20px'>"
            . "<a href='{$dashboard_url}' style='background:{$primary};color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px'>Track My Service →</a>"
            . "</p>"
            . "<p style='color:#9ca3af;font-size:12px;text-align:center;margin:0'>Thank you for choosing {$site_name}. You will receive regular updates on your dashboard.</p>"
            . "</td></tr></table></td></tr></table></body></html>";

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ( $settings['email_from_name'] ?? $site_name ) . ' <' . ( $settings['email_from'] ?? get_option('admin_email') ) . '>',
        ];

        add_filter( 'wp_mail_content_type', fn() => 'text/html' );
        wp_mail( $wp_user->user_email, $subject, $html, $headers );
        remove_all_filters( 'wp_mail_content_type' );
    }
}
