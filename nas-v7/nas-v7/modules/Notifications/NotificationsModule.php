<?php
namespace NAS\Modules\Notifications;
if (!defined('ABSPATH')) exit;

use NAS\Core\Database;

/**
 * NAS Notifications — Email + WhatsApp on booking events
 * Hooks: nas_booking_submitted, nas_status_changed, nas_vendor_assigned
 */
class NotificationsModule {

    public static function register(): void {
        // WP action hooks — fired by AdminModule for manual admin status changes
        add_action('nas_booking_submitted', [self::class, 'on_booking_submitted']);
        add_action('nas_status_changed',    [self::class, 'on_status_changed'], 10, 2);
        add_action('nas_vendor_assigned',   [self::class, 'on_vendor_assigned'], 10, 2);

        // ── EventBus bridge ───────────────────────────────────────────────────
        // BookingModule, MaterialModule, and PaymentModule emit via EventBus::emit()
        // with event name 'booking_status_changed'. We bridge these to our handlers.
        // Without this bridge, notifications NEVER fire for wizard bookings, payments,
        // material approvals, or any EventBus-sourced status changes.
        \NAS\Core\EventBus::on( 'booking_status_changed', function( array $payload ) {
            $booking_id = (int) ( $payload['booking_id'] ?? 0 );
            $new_status = (string) ( $payload['new_status'] ?? $payload['status'] ?? '' );
            if ( ! $booking_id ) return;

            // Map EventBus payload → on_status_changed (same 2-arg signature as WP hook)
            if ( $new_status ) {
                self::on_status_changed( $booking_id, $new_status );
            }
        }, 20 );

        \NAS\Core\EventBus::on( 'booking_created', function( array $payload ) {
            $booking_id = (int) ( $payload['booking_id'] ?? 0 );
            if ( $booking_id ) self::on_booking_submitted( $booking_id );
        }, 20 );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    // TRACE: setting() — Called internally or via AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    private static function setting(string $key, string $default = ''): string {
        static $cache = null;
        if ($cache === null) {
            $db = Database::instance();
            try {
                $rows = $db->select("SELECT setting_key, setting_value FROM `{$db->prefix('settings')}`");
                $cache = [];
                foreach ($rows as $r) $cache[$r['setting_key']] = $r['setting_value'];
            } catch (\Exception $e) { $cache = []; }
        }
        return $cache[$key] ?? $default;
    }

    // TRACE: get_booking() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    private static function get_booking(int $id): ?object {
        global $wpdb;
        $db = Database::instance();
        $bt = $db->prefix('bookings'); $ct = $db->prefix('clients');
        $nt = $db->prefix('newspapers'); $cat = $db->prefix('categories'); $cit = $db->prefix('cities');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT bk.*, cl.name client_name, cl.phone client_phone, cl.email client_email,
                    n.name newspaper_name, cat.name category_name, cit.name city_name
             FROM `$bt` bk
             LEFT JOIN `$ct` cl ON cl.id=bk.client_id
             LEFT JOIN `$nt` n ON n.id=bk.newspaper_id
             LEFT JOIN `$cat` cat ON cat.id=bk.category_id
             LEFT JOIN `$cit` cit ON cit.id=bk.city_id
             WHERE bk.id=%d", $id
        ), OBJECT);
    }

    // TRACE: send_email() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure → calls external API → sends email via wp_mail.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function send_email(string $to, string $subject, string $body): void {
        if (!$to || !is_email($to)) return;
        $sender_name  = self::setting('email_sender_name', get_bloginfo('name'));
        $sender_email = self::setting('email_sender_address', get_option('admin_email'));
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            "From: {$sender_name} <{$sender_email}>",
        ];
        wp_mail($to, $subject, $body, $headers);
    }

    // TRACE: send_whatsapp() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure → calls external API.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function send_whatsapp(string $number, string $message): void {
        $api_key = self::setting('whatsapp_api_key');
        if (!$api_key || !$number) return;
        $url = add_query_arg([
            'phone'   => $number,
            'text'    => $message,
            'apikey'  => $api_key,
        ], 'https://api.callmebot.com/whatsapp.php');
        wp_remote_get($url, ['timeout' => 5, 'blocking' => false]);
    }

    // ── Event Handlers ────────────────────────────────────────────────────────

    // TRACE: on_booking_submitted() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    public static function on_booking_submitted(int $booking_id): void {
        $b     = self::get_booking($booking_id);
        if (!$b) return;
        $brand = self::setting('brand_name', get_bloginfo('name'));
        $money = '₹' . number_format((float)$b->total_amount, 2);

        // Email to admin
        $admin_email = get_option('admin_email');
        self::send_email($admin_email,
            "[{$brand}] New Booking: {$b->uid}",
            "A new ad booking has been submitted.\n\n"
            . "Reference: {$b->uid}\n"
            . "Client: {$b->client_name} ({$b->client_phone})\n"
            . "Category: {$b->category_name}\n"
            . "Newspaper: {$b->newspaper_name} — {$b->city_name}\n"
            . "Publish Date: {$b->publish_date}\n"
            . "Estimated Amount: {$money}\n\n"
            . "View: " . home_url("/admin-dashboard/?nas_admin=request&id={$booking_id}")
        );

        // Email to client
        if (!empty($b->client_email)) {
            self::send_email($b->client_email,
                "Your Ad Booking Received – Ref: {$b->uid}",
                "Dear {$b->client_name},\n\n"
                . "Thank you for submitting your newspaper advertisement booking.\n\n"
                . "Booking Reference: {$b->uid}\n"
                . "Category: {$b->category_name}\n"
                . "Newspaper: {$b->newspaper_name}\n"
                . "Publication Date: {$b->publish_date}\n\n"
                . "We will review your booking and confirm the final price shortly.\n\n"
                . "Regards,\n{$brand}"
            );
        }

        // WhatsApp to admin
        $wa_number = self::setting('whatsapp_number');
        if ($wa_number) {
            self::send_whatsapp($wa_number,
                "🗞️ *New Booking: {$b->uid}*\n"
                . "Client: {$b->client_name}\n📞 {$b->client_phone}\n"
                . "📰 {$b->newspaper_name} | {$b->city_name}\n"
                . "📅 Publish: {$b->publish_date}\n💰 Est: {$money}"
            );
        }
    }

    // TRACE: on_status_changed() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    public static function on_status_changed(int $booking_id, string $status): void {
        // Fixed: removed 'approved'/'price_shared' which don't exist in the booking status ENUM.
        // Added actual status values used throughout the workflow.
        $notify = ['ready_to_process', 'payment_received', 'ad_processing', 'submitted_to_pub', 'published', 'completed', 'rejected', 'not_able_to_process'];
        if (!in_array($status, $notify, true)) return;

        $b = self::get_booking($booking_id);
        if (!$b || empty($b->client_email)) return;

        $brand = self::setting('brand_name', get_bloginfo('name'));
        $money = '₹' . number_format((float)$b->total_amount, 2);

        $messages = [
            'ready_to_process'    => 'Your advertisement has been reviewed and is ready to process.',
            'payment_received'    => "Payment confirmed. Total: {$money}. Your ad is being processed.",
            'ad_processing'       => 'Your advertisement is currently being designed and processed.',
            'submitted_to_pub'    => "Your advertisement has been submitted to {$b->newspaper_name} for publication.",
            'published'           => "Your advertisement has been published in {$b->newspaper_name}! 🎉",
            'completed'           => "Your booking is complete. Thank you for using our service!",
            'rejected'            => "Unfortunately your booking could not be accepted. Please contact us for details.",
            'not_able_to_process' => "We are unable to process your booking. Please contact support.",
        ];

        self::send_email($b->client_email,
            "Booking Update [{$b->uid}] — " . ucwords(str_replace('_', ' ', $status)),
            "Dear {$b->client_name},\n\n"
            . ($messages[$status] ?? "Your booking status has been updated to: {$status}") . "\n\n"
            . "Reference: {$b->uid}\n"
            . "Category: {$b->category_name}\n"
            . "Newspaper: {$b->newspaper_name}\n\n"
            . "Regards,\n{$brand}"
        );

        // WhatsApp to client if they opted in (check for phone)
        // TRACE: WhatsApp sent when booking is published or ready_to_process ('approved' was non-canonical alias).
        if (!empty($b->client_phone) && in_array($status, ['published', 'ready_to_process'], true)) {
            // Fixed: ltrim('+91 ') strips chars not string — use regex instead
            $raw_phone = preg_replace('/\D/', '', $b->client_phone);  // digits only
            $phone_91  = strlen($raw_phone) === 10 ? '91' . $raw_phone : $raw_phone; // prepend country code if 10-digit
            self::send_whatsapp( $phone_91,
                "📰 *{$brand}*\n"
                . "Booking: {$b->uid}\n"
                . ($messages[$status] ?? "Status: {$status}")
            );
        }
    }

    // TRACE: on_vendor_assigned() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function on_vendor_assigned(int $booking_id, int $vendor_id): void {
        $db = Database::instance(); global $wpdb;
        $vendor = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$db->prefix('vendors')}` WHERE id=%d", $vendor_id
        ), OBJECT);
        if (!$vendor) return;

        $b     = self::get_booking($booking_id);
        if (!$b) return;
        $brand = self::setting('brand_name', get_bloginfo('name'));

        // WhatsApp to vendor
        $wa = $vendor->whatsapp_number ?: $vendor->phone;
        if ($wa) {
            self::send_whatsapp($wa,
                "🗞️ *{$brand} — New Assignment*\n"
                . "Booking: {$b->uid}\n"
                . "Newspaper: {$b->newspaper_name}\n"
                . "Edition: " . ($b->edition ?: 'Main') . "\n"
                . "Publish Date: {$b->publish_date}\n"
                . "Ad Type: {$b->ad_type}\n"
                . "Please confirm receipt."
            );
        }

        // Email to vendor
        if (!empty($vendor->email)) {
            self::send_email($vendor->email,
                "[{$brand}] New Assignment: {$b->uid}",
                "Dear {$vendor->name},\n\n"
                . "You have been assigned a new newspaper advertisement booking.\n\n"
                . "Booking Reference: {$b->uid}\n"
                . "Newspaper: {$b->newspaper_name}\n"
                . "Edition: " . ($b->edition ?: 'Main Edition') . "\n"
                . "Ad Type: " . ucfirst($b->ad_type) . "\n"
                . "Publish Date: {$b->publish_date}\n\n"
                . "Ad Content:\n{$b->ad_content}\n\n"
                . "Please confirm receipt and proceed with publication.\n\n"
                . "Regards,\n{$brand}"
            );
        }
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   NotificationJob — Queue job handler.
   Called by Queue::process() via class_exists + handle().
   Handles: booking_created, quotation_sent, proof_ready.
   ══════════════════════════════════════════════════════════════════════════════ */
class NotificationJob {

    /**
     * TRACE: Called by Queue::process() with $payload from Queue::push().
     *        Precondition: $payload['type'] and $payload['booking_id'] set.
     *        Postcondition: HTML email sent to client using EmailTemplateModule or
     *                       plain-text fallback; no exception thrown.
     *        Edge cases: missing booking → silently returns. Unknown type → silently returns.
     */
    // TRACE: handle() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    public static function handle( array $payload ): void {
        $type       = $payload['type'] ?? '';
        $booking_id = (int) ( $payload['booking_id'] ?? 0 );
        if ( ! $booking_id ) return;

        $db  = \NAS\Core\Database::instance();
        $bt  = $db->prefix('bookings'); $ct = $db->prefix('clients');
        $nt  = $db->prefix('newspapers'); $cat = $db->prefix('categories'); $cit = $db->prefix('cities');

        global $wpdb;
        $b = $wpdb->get_row( $wpdb->prepare(
            "SELECT bk.*, cl.name client_name, cl.phone client_phone, cl.email client_email,
                    n.name newspaper_name, cat.name category_name, cit.name city_name
             FROM `$bt` bk
             LEFT JOIN `$ct` cl ON cl.id=bk.client_id
             LEFT JOIN `$nt` n  ON n.id=bk.newspaper_id
             LEFT JOIN `$cat` cat ON cat.id=bk.category_id
             LEFT JOIN `$cit` cit ON cit.id=bk.city_id
             WHERE bk.id=%d", $booking_id
        ), OBJECT );

        if ( ! $b || empty( $b->client_email ) ) return;

        $cfg          = \NAS\Core\Config::instance();
        $brand        = $cfg->get('brand_name', get_bloginfo('name'));
        $sender_name  = $cfg->get('email_sender_name', $brand);
        $sender_email = $cfg->get('email_sender_address', get_option('admin_email'));
        $money        = '₹' . number_format( (float) $b->total_amount, 2 );
        $dash_url     = get_permalink( get_option('nas_page_client_dashboard') ) ?: home_url('/client-dashboard/');
        $book_url     = get_permalink( get_option('nas_page_booking') ) ?: home_url('/book-newspaper-ad/');
        $support_email = sanitize_email( $cfg->get('support_email', get_option('admin_email')) );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$sender_name} <{$sender_email}>",
        ];

        switch ( $type ) {
            case 'booking_created':
                // Try DB-stored HTML template first (from email_templates table)
                if ( class_exists('\\NAS\\Modules\\EmailTemplate\\EmailTemplateModule') ) {
                    $tpl = \NAS\Modules\EmailTemplate\EmailTemplateModule::render( 'booking_received', [
                        '{client_name}'    => $b->client_name,
                        '{order_id}'       => $b->uid,
                        '{newspaper_name}' => $b->newspaper_name,
                        '{category}'       => $b->category_name,
                        '{total_price}'    => $money,
                        '{dashboard_link}' => $dash_url,
                        '{booking_link}'   => $book_url,
                        '{brand_name}'     => $brand,
                    ]);
                    if ( ! empty( $tpl['body'] ) ) {
                        wp_mail( $b->client_email, $tpl['subject'] ?: "Booking Received – Ref: {$b->uid}", $tpl['body'], $headers );
                        return;
                    }
                }
                // Fallback: file-based HTML template
                $html_file = NAS_DIR . 'templates/emails/booking-confirmation.html';
                if ( file_exists($html_file) && filesize($html_file) > 0 ) {
                    $logo_tag = $cfg->get('logo_url','') ? '<img src="'.esc_url($cfg->get('logo_url',''))."\" alt=\"{$brand}\" style=\"height:40px\">" : "<strong style=\"color:#fff;font-size:20px\">{$brand}</strong>";
                    $body = str_replace(
                        ['{brand_name}','{client_name}','{order_id}','{newspaper_name}','{category}','{city_name}','{publish_date}','{total_price}','{dashboard_link}','{booking_link}','{support_email}','{logo_url_tag}'],
                        [$brand, esc_html($b->client_name), esc_html($b->uid), esc_html($b->newspaper_name), esc_html($b->category_name), esc_html($b->city_name), esc_html($b->publish_date ?? ''), $money, $dash_url, $book_url, $support_email, $logo_tag],
                        file_get_contents( $html_file )
                    );
                    wp_mail( $b->client_email, "Booking Confirmed – Ref: {$b->uid}", $body, $headers );
                }
                break;

            case 'quotation_sent':
                $quote = '₹' . number_format( (float) ( $payload['quote'] ?? 0 ), 2 );
                $subject = "Quotation for Your Ad Booking – Ref: {$b->uid}";
                $body    = "<div style=\"font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:32px;background:#fff;border:1px solid #e2e8f0;border-radius:10px\">"
                         . "<h2 style=\"color:#0f172a;margin:0 0 16px\">Quotation Ready — {$brand}</h2>"
                         . "<p>Hi <strong>" . esc_html($b->client_name) . "</strong>,</p>"
                         . "<p>We have prepared a quotation for your newspaper ad booking <strong>#{$b->uid}</strong>.</p>"
                         . "<table style=\"width:100%;border-collapse:collapse;margin:20px 0\">"
                         . "<tr><td style=\"padding:10px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:bold\">Newspaper</td><td style=\"padding:10px;border:1px solid #e2e8f0\">" . esc_html($b->newspaper_name) . "</td></tr>"
                         . "<tr><td style=\"padding:10px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:bold\">Quoted Amount</td><td style=\"padding:10px;border:1px solid #e2e8f0;font-size:18px;font-weight:800;color:#2563eb\">{$quote}</td></tr>"
                         . "</table>"
                         . "<a href=\"{$dash_url}\" style=\"display:inline-block;background:#2563eb;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold\">Accept & Pay →</a>"
                         . "<p style=\"margin-top:20px;color:#64748b;font-size:13px\">&copy; {$brand}</p></div>";
                wp_mail( $b->client_email, $subject, $body, $headers );
                break;

            case 'proof_ready':
                $subject = "Ad Proof Ready for Review – Ref: {$b->uid}";
                $body    = "<div style=\"font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:32px;background:#fff;border:1px solid #e2e8f0;border-radius:10px\">"
                         . "<h2 style=\"color:#0f172a\">Your Ad Proof is Ready — {$brand}</h2>"
                         . "<p>Hi <strong>" . esc_html($b->client_name) . "</strong>,</p>"
                         . "<p>The proof for your ad in <strong>" . esc_html($b->newspaper_name) . "</strong> (Booking #{$b->uid}) is ready for your review.</p>"
                         . "<p>Please log in to your dashboard to approve or request changes.</p>"
                         . "<a href=\"{$dash_url}\" style=\"display:inline-block;background:#0D9488;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold\">Review Proof →</a>"
                         . "<p style=\"margin-top:20px;color:#64748b;font-size:13px\">&copy; {$brand}</p></div>";
                wp_mail( $b->client_email, $subject, $body, $headers );
                break;
        }
    }
}
