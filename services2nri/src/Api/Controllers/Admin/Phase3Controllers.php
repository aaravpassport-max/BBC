<?php
/**
 * Phase 3 Controllers — UX completeness, dashboard enhancements,
 * document proxy, AVIF conversion, email wiring, advanced features.
 *
 * 4.13 Email sent badge — mark is_email_sent on message when sent via email
 * 4.18 Service Not Available state — new status + valid transitions
 * 4.20 AVIF/WebP → PNG conversion — document proxy + admin batch tool
 * 5.1  Admin dashboard: revenue widget, overdue requests, today's activity
 * 5.2  Customer dashboard: pending payment count, upcoming delivery count
 * 5.3  Template-based event emails for completion, dispatch, doc reminder
 * M-20 Ticket search on request detail page
 */

namespace S2NRI\Api\Controllers\Admin;

use S2NRI\Api\{Request, Response};
use S2NRI\Api\Controllers\BaseController;

defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════════════════
// DocumentProxyController
// 4.20: Secure document download proxy with AVIF/WebP → PNG conversion
// ══════════════════════════════════════════════════════════════════════════════

class DocumentProxyController extends BaseController {

    /**
     * GET documents/{doc_id}/download
     * Secure proxied download for a booking document.
     *
     * TRACE: auth check (customer owns booking OR staff) →
     *        get document row → validate file URL →
     *        if AVIF/WebP: convert to PNG using GD or WP functions →
     *        stream with Content-Disposition: attachment.
     *
     * PRECONDITIONS: document exists, user is authorised.
     * POSTCONDITIONS: file streamed to browser with forced download.
     * EDGE CASES: file not found on disk → try HTTP download.
     *             GD not available → stream original AVIF/WebP.
     *             Access denied → 403.
     */
    public function download( Request $req ): void {
        global $wpdb;
        $p      = $wpdb->prefix;
        $doc_id = (int) $req->param('doc_id');

        $doc = $wpdb->get_row( $wpdb->prepare(
            "SELECT d.*, b.customer_id, b.booking_ref
             FROM {$p}s2nri_documents d
             JOIN {$p}s2nri_bookings b ON b.id = d.booking_id
             WHERE d.id = %d LIMIT 1",
            $doc_id
        ), ARRAY_A );

        if ( ! $doc ) { status_header(404); echo 'Document not found.'; exit; }

        // Auth check
        $is_staff = $this->userIsStaff();
        if ( ! $is_staff ) {
            // Customer must own the booking AND document must be visible to customer
            $customer = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id=%d LIMIT 1", $this->user['wp_id']
            ) );
            if ( ! $customer || (int)$doc['customer_id'] !== (int)$customer || ! (int)$doc['is_visible_to_customer'] ) {
                status_header(403); echo 'Access denied.'; exit;
            }
        }

        $file_url  = $doc['file_url'];
        $file_name = $doc['file_name'] ?: basename($file_url);
        $mime      = $doc['mime_type'] ?: '';

        // Detect AVIF / WebP
        $is_avif = ( $mime === 'image/avif' || str_ends_with(strtolower($file_url), '.avif') );
        $is_webp = ( $mime === 'image/webp'  || str_ends_with(strtolower($file_url), '.webp') );

        // Get file content
        $upload_dir   = wp_upload_dir()['basedir'];
        $upload_url   = wp_upload_dir()['baseurl'];
        $local_path   = null;

        if ( strpos($file_url, $upload_url) === 0 ) {
            $local_path = $upload_dir . substr($file_url, strlen($upload_url));
        }

        if ( ($is_avif || $is_webp) && function_exists('imagecreatefromstring') ) {
            // Convert to PNG via GD
            $raw_content = null;
            if ( $local_path && file_exists($local_path) ) {
                $raw_content = file_get_contents($local_path);
            } else {
                $resp = wp_remote_get($file_url, ['timeout'=>30]);
                if ( ! is_wp_error($resp) ) $raw_content = wp_remote_retrieve_body($resp);
            }

            if ( $raw_content ) {
                $img = @imagecreatefromstring($raw_content);
                if ( $img !== false ) {
                    $png_name = preg_replace('/\.(avif|webp)$/i', '.png', $file_name) ?: $file_name . '.png';
                    header('Content-Type: image/png');
                    header('Content-Disposition: attachment; filename="' . sanitize_file_name($png_name) . '"');
                    header('Cache-Control: no-store');
                    imagepng($img);
                    imagedestroy($img);
                    exit;
                }
            }
            // GD failed — fall through to raw stream
        }

        // Stream original file
        if ( $local_path && file_exists($local_path) ) {
            $content_type = $mime ?: 'application/octet-stream';
            header('Content-Type: ' . $content_type);
            header('Content-Disposition: attachment; filename="' . sanitize_file_name($file_name) . '"');
            header('Content-Length: ' . filesize($local_path));
            header('Cache-Control: no-store');
            readfile($local_path);
            exit;
        }

        // Redirect to original URL as last resort
        wp_redirect($file_url);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// AvifConversionController
// 4.20: Admin batch AVIF/WebP → PNG conversion tool
// ══════════════════════════════════════════════════════════════════════════════

class AvifConversionController extends BaseController {

    /**
     * GET admin/avif-scan
     * Scans s2nri_documents for AVIF/WebP files and returns count + list.
     *
     * TRACE: requireManager → query documents WHERE mime_type IN (avif,webp)
     *        OR file_name LIKE %.avif OR %.webp → return list.
     */
    public function scan( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT d.id, d.file_name, d.file_url, d.mime_type, b.booking_ref
             FROM {$wpdb->prefix}s2nri_documents d
             JOIN {$wpdb->prefix}s2nri_bookings b ON b.id=d.booking_id
             WHERE d.mime_type IN ('image/avif','image/webp')
                OR d.file_name LIKE '%.avif' OR d.file_name LIKE '%.webp'
             LIMIT 200",
            ARRAY_A
        );
        Response::json(['documents' => $rows ?: [], 'total' => count($rows ?: []), 'gd_available' => function_exists('imagecreatefromstring')]);
    }

    /**
     * POST admin/avif-convert/{doc_id}
     * Converts a single AVIF/WebP document to PNG in place.
     *
     * TRACE: requireManager → get document → load file → GD imagecreatefromstring →
     *        imagepng to new file path → wp_insert_attachment or update WP media →
     *        UPDATE s2nri_documents SET file_url, file_name, mime_type →
     *        return { success, new_url, new_name }.
     * EDGE CASES: GD not available → 503.
     *             File already PNG → 422.
     *             GD cannot decode AVIF → 422 with message.
     */
    public function convert( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $doc_id = (int) $req->param('doc_id');

        if ( ! function_exists('imagecreatefromstring') ) {
            Response::json(['error' => 'GD extension is not available on this server. Cannot convert images.'], 503); return;
        }

        $doc = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_documents WHERE id=%d LIMIT 1", $doc_id
        ), ARRAY_A );
        if ( ! $doc ) { Response::json(['error'=>'Document not found.'], 404); return; }

        $mime = $doc['mime_type'];
        $is_avif = $mime === 'image/avif' || str_ends_with(strtolower($doc['file_url']), '.avif');
        $is_webp = $mime === 'image/webp'  || str_ends_with(strtolower($doc['file_url']), '.webp');
        if ( ! $is_avif && ! $is_webp ) {
            Response::json(['error' => 'Document is not AVIF or WebP format.'], 422); return;
        }

        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        $upload_base = $upload_dir['basedir'];

        // Get local path
        $local_path = null;
        if ( strpos($doc['file_url'], $upload_url) === 0 ) {
            $local_path = $upload_base . substr($doc['file_url'], strlen($upload_url));
        }

        $raw_content = null;
        if ( $local_path && file_exists($local_path) ) {
            $raw_content = file_get_contents($local_path);
        } else {
            $resp = wp_remote_get($doc['file_url'], ['timeout'=>30]);
            if ( is_wp_error($resp) ) { Response::json(['error'=>'Could not fetch file: '.$resp->get_error_message()], 500); return; }
            $raw_content = wp_remote_retrieve_body($resp);
        }

        $img = @imagecreatefromstring($raw_content);
        if ( $img === false ) {
            Response::json(['error'=>'GD could not decode the image. AVIF conversion requires PHP 8.1+ and libavif.'], 422); return;
        }

        // Save PNG alongside original
        $new_file_name = preg_replace('/\.(avif|webp)$/i', '.png', basename($doc['file_url']));
        $subdir        = dirname( $local_path ? $local_path : $upload_base . '/s2nri-docs' );
        $new_local     = $subdir . '/' . $new_file_name;
        $new_url       = str_replace(basename($doc['file_url']), $new_file_name, $doc['file_url']);

        $ok = imagepng($img, $new_local);
        imagedestroy($img);

        if ( ! $ok ) { Response::json(['error'=>'Failed to write PNG file. Check directory permissions.'], 500); return; }

        // CHECKED (was previously unchecked) — and this one is more
        // severe than the usual pattern: the original file was
        // (correctly, per code order) deleted AFTER this update, but
        // UNCONDITIONALLY — regardless of whether the update itself
        // actually succeeded. A silently failed update here previously
        // still proceeded to permanently delete the original AVIF/WebP
        // file, leaving the document record pointing at the OLD file_url
        // — which no longer exists. That is real, irreversible data loss
        // of a customer's uploaded document, not just a UI inconsistency.
        $doc_update = $wpdb->update($wpdb->prefix . 's2nri_documents', [
            'file_url'   => $new_url,
            'file_name'  => str_replace(['.avif','.webp'], '.png', $doc['file_name']),
            'mime_type'  => 'image/png',
        ], ['id' => $doc_id]);

        if ( $doc_update === false ) {
            error_log( '[S2NRI] AVIF conversion: document record update failed for doc ' . $doc_id . ': ' . $wpdb->last_error . '. New PNG was saved at ' . $new_local . ' but the original file was NOT deleted and the document record still points to the old file.' );
            // Do NOT delete the original — the record still points to it.
            // The new PNG file is orphaned on disk but that costs only
            // disk space, not a customer's document.
            Response::json(['error' => 'Converted the image but failed to update the document record. The original file was kept. Please try again.'], 500); return;
        }

        // Only now — after confirming the record correctly points to the
        // new PNG — is it safe to remove the original.
        if ( $local_path && file_exists($local_path) ) {
            @unlink($local_path);
        }

        Response::json(['success' => true, 'new_url' => $new_url, 'new_name' => $new_file_name]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// DashboardEnhancementController
// 5.1: Admin dashboard: revenue widget, overdue requests, today's activity
// 5.2: Customer dashboard: pending payment count, upcoming delivery count
// ══════════════════════════════════════════════════════════════════════════════

class DashboardEnhancementController extends BaseController {

    /**
     * GET admin/dashboard/extended
     * Extends the existing admin dashboard with Phase 3 widgets.
     *
     * Returns:
     *   revenue_summary: { total_quotes_sent, total_approved_amount, pending_payments_value }
     *   today_activity: { submitted_today, status_updates_today }
     *   overdue: { count, bookings[] } — bookings with no update in > N days (configurable)
     *   due_this_week: { count } — bookings with due_date in next 7 days
     *
     * TRACE: requireStaff → run 4 separate queries → compact and return.
     * EDGE CASES: overdue_days setting missing → defaults to 5.
     */
    public function extendedDashboard( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p = $wpdb->prefix;

        $today        = current_time('Y-m-d');
        $overdue_days = (int) \S2NRI\Models\Setting::get('overdue_threshold_days', '5');
        if ($overdue_days < 1) $overdue_days = 5;

        // Revenue summary: total quoted, total approved, pending payment value
        $revenue_summary = [
            'total_quotes_sent'    => (float) $wpdb->get_var("SELECT COALESCE(SUM(quoted_amount),0) FROM {$p}s2nri_bookings WHERE quoted_amount IS NOT NULL"),
            'total_approved_amount'=> (float) $wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$p}s2nri_payments WHERE status='verified'"),
            'pending_payment_value'=> (float) $wpdb->get_var("SELECT COALESCE(SUM(b.quoted_amount),0) FROM {$p}s2nri_bookings b WHERE b.payment_status IN ('pending','bank_transfer_pending') AND b.quoted_amount IS NOT NULL"),
            'pending_payment_count'=> (int)   $wpdb->get_var("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE payment_status IN ('pending','bank_transfer_pending') AND quoted_amount IS NOT NULL"),
        ];

        // Today's activity
        $today_activity = [
            'submitted_today'     => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE DATE(created_at)=%s", $today
            )),
            'status_updates_today'=> (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}s2nri_audit_log WHERE action='status_changed' AND DATE(created_at)=%s", $today
            )),
            'emails_sent_today'   => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}s2nri_email_log WHERE DATE(created_at)=%s AND status='sent'", $today
            )),
            'payments_verified_today' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}s2nri_payments WHERE DATE(verified_at)=%s AND status='verified'", $today
            )),
        ];

        // Overdue bookings: active bookings with updated_at older than overdue_days
        $overdue_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT b.id, b.booking_ref, b.status, b.updated_at,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name,
                    s.name AS service_name,
                    DATEDIFF(NOW(), b.updated_at) AS days_since_update
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id=b.customer_id
             LEFT JOIN {$p}users u ON u.ID=cu.wp_user_id
             WHERE b.status NOT IN ('completed','cancelled','service_not_available')
               AND b.updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY b.updated_at ASC LIMIT 20",
            $overdue_days
        ), ARRAY_A);

        $overdue = [
            'threshold_days' => $overdue_days,
            'count'          => count($overdue_rows),
            'bookings'       => $overdue_rows,
        ];

        // Bookings due this week (due_date in next 7 days, not yet completed)
        $due_this_week = [
            'count'    => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}s2nri_bookings
                 WHERE due_date BETWEEN %s AND DATE_ADD(%s, INTERVAL 7 DAY)
                   AND status NOT IN ('completed','cancelled','service_not_available')",
                $today, $today
            )),
            'bookings' => $wpdb->get_results($wpdb->prepare(
                "SELECT b.id, b.booking_ref, b.status, b.due_date, b.delivery_working_days,
                        COALESCE(u.display_name, '') AS customer_name, s.name AS service_name
                 FROM {$p}s2nri_bookings b
                 LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
                 LEFT JOIN {$p}s2nri_customers cu ON cu.id=b.customer_id
                 LEFT JOIN {$p}users u ON u.ID=cu.wp_user_id
                 WHERE b.due_date BETWEEN %s AND DATE_ADD(%s, INTERVAL 7 DAY)
                   AND b.status NOT IN ('completed','cancelled','service_not_available')
                 ORDER BY b.due_date ASC LIMIT 10",
                $today, $today
            ), ARRAY_A) ?: [],
        ];

        Response::json(compact('revenue_summary','today_activity','overdue','due_this_week'));
    }

    /**
     * GET customer/dashboard-stats
     * Returns extended stats for the customer dashboard (5.2).
     *
     * Returns:
     *   total, active, completed — existing stats
     *   pending_payment — bookings awaiting payment from this customer
     *   due_this_week — bookings with due_date in next 7 days
     *
     * TRACE: auth required → get customer_id →
     *        5 COUNT queries → return JSON.
     */
    public function customerStats( Request $req ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Ensure customer profile exists (same reason as myList)
        \S2NRI\Models\User::ensureCustomerProfile( $this->user['wp_id'] );

        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id=%d LIMIT 1",
            $this->user['wp_id']
        ), ARRAY_A);

        if ( ! $customer ) {
            Response::json(['total'=>0,'active'=>0,'completed'=>0,'pending_payment'=>0,'due_this_week'=>0]);
            return;
        }
        $cid   = (int) $customer['id'];
        $today = current_time('Y-m-d');

        Response::json([
            'total'          => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE customer_id=%d", $cid)),
            'active'         => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE customer_id=%d AND status NOT IN ('completed','cancelled','service_not_available')", $cid)),
            'completed'      => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE customer_id=%d AND status='completed'", $cid)),
            'pending_payment'=> (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE customer_id=%d AND payment_status IN ('pending','bank_transfer_pending') AND quoted_amount IS NOT NULL",
                $cid
            )),
            'due_this_week'  => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE customer_id=%d AND due_date BETWEEN %s AND DATE_ADD(%s, INTERVAL 7 DAY) AND status NOT IN ('completed','cancelled')",
                $cid, $today, $today
            )),
        ]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// Phase3BookingController
// 5.3: Template-based event emails for completion/dispatch/document reminder
// 4.13: Mark is_email_sent on message when sent via email (send-via-email)
// 4.18: service_not_available status handling
// M-20: Ticket search on request detail
// ══════════════════════════════════════════════════════════════════════════════

class Phase3BookingController extends BaseController {

    /**
     * POST admin/bookings/{id}/messages with body { send_via_email: true }
     * Enhancement to existing sendMessage — marks the new message row with
     * is_email_sent=1 when send_via_email is true (4.13: email sent badge).
     *
     * This controller handles the admin-side message + email send in one atomic operation:
     * INSERT message → if send_via_email: wp_mail to customer → UPDATE is_email_sent=1.
     *
     * TRACE: requireStaff → validate message → INSERT into s2nri_messages →
     *        if send_via_email: send email template → UPDATE is_email_sent=1 on message row.
     * EDGE CASES: email send failure → message saved, is_email_sent stays 0, error surfaced.
     *             Internal message (is_internal=1): never email, ignore send_via_email.
     */
    public function sendMessageWithEmailBadge( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param('id');
        $message    = sanitize_textarea_field( $req->input('message','') );
        $is_internal= (bool) $req->input('is_internal', false);
        $send_email = (bool) $req->input('send_via_email', false) && ! $is_internal;

        if ( ! $message ) { Response::json(['error'=>'Message is required.'], 422); return; }

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, s.name AS service_name, c.wp_user_id, cu.id AS cust_id
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
             JOIN {$p}s2nri_customers c ON c.id=b.customer_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id=b.customer_id
             WHERE b.id=%d LIMIT 1",
            $booking_id
        ), ARRAY_A);
        if ( ! $booking ) { Response::json(['error'=>'Booking not found.'], 404); return; }

        // Insert message
        $msg_insert = $wpdb->insert($p . 's2nri_messages', [
            'booking_id'    => $booking_id,
            'sender_id'     => get_current_user_id(),
            'sender_type'   => 'staff',
            'message'       => $message,
            'is_internal'   => $is_internal ? 1 : 0,
            'is_email_sent' => 0,
            'created_at'    => current_time('mysql'),
        ]);
        // CHECKED (was previously unchecked): a silently failed insert
        // here previously still fell through to emailing the customer
        // "New message from our team", sending an in-app notification,
        // and logging "message_sent" — for a message that was never
        // actually saved. Stop before any of that if the save itself
        // failed.
        if ( $msg_insert === false ) {
            error_log( '[S2NRI] Staff message insert failed for booking ' . $booking_id . ': ' . $wpdb->last_error );
            Response::json(['error' => 'Failed to send message. Please try again.'], 500); return;
        }
        $msg_id = $wpdb->insert_id;

        $email_sent = false;
        $email_error = '';

        if ( $send_email && $booking['wp_user_id'] ) {
            $wp_user = get_user_by('id', $booking['wp_user_id']);
            $to      = $wp_user ? $wp_user->user_email : '';

            if ( $to ) {
                $site_name  = \S2NRI\Models\Setting::get('platform_name','Services2NRI');
                $primary    = \S2NRI\Models\Setting::get('primary_color','#4A6FA5');
                $portal_url = home_url('/dashboard/bookings/'.$booking_id);
                $name       = $wp_user->display_name ?: 'Valued Customer';

                $subject  = "New message on your application {$booking['booking_ref']}";
                $msg_html = nl2br(esc_html($message));
                $body     = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center' style='padding:28px 16px'>
<table width='560' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
<tr><td style='background:{$primary};padding:22px 32px;text-align:center'>
  <h1 style='color:#fff;margin:0;font-size:18px'>".esc_html($site_name)."</h1>
</td></tr>
<tr><td style='padding:28px 32px'>
  <p style='font-size:13px;color:#888;margin:0 0 4px'>Application: ".esc_html($booking['booking_ref'])."</p>
  <h2 style='color:#1E2D40;margin:0 0 18px;font-size:17px'>New Message from Our Team</h2>
  <p style='color:#555;font-size:14px;line-height:1.7;margin:0 0 14px'>Dear ".esc_html($name).",</p>
  <div style='background:#f8fafc;border-left:4px solid {$primary};border-radius:0 8px 8px 0;padding:16px 20px;margin:0 0 20px'>
    <p style='font-size:14px;color:#374151;margin:0;line-height:1.7'>{$msg_html}</p>
  </div>
  <p style='text-align:center;margin:0'>
    <a href='".esc_url($portal_url)."' style='background:{$primary};color:#fff;text-decoration:none;padding:12px 28px;border-radius:7px;font-size:14px;font-weight:700;display:inline-block'>Reply on Dashboard →</a>
  </p>
</td></tr>
<tr><td style='background:#f8fafc;padding:14px 32px;text-align:center;border-top:1px solid #e2e8f0'>
  <p style='color:#aaa;font-size:11px;margin:0'>".esc_html($site_name)."</p>
</td></tr>
</table></td></tr></table></body></html>";

                // FIXED: same remove_filter closure-identity bug
                // fixed elsewhere this session.
                $html_filter_a = fn() => 'text/html';
                add_filter('wp_mail_content_type', $html_filter_a);
                $email_sent = wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
                remove_filter('wp_mail_content_type', $html_filter_a);

                if ($email_sent) {
                    if ( $wpdb->update($p.'s2nri_messages', ['is_email_sent'=>1], ['id'=>$msg_id]) === false ) {
                        error_log( '[S2NRI] Failed to set is_email_sent flag for message ' . $msg_id . ': ' . $wpdb->last_error );
                    }
                } else {
                    $email_error = 'Email failed to send — check SMTP settings.';
                }

                // Log to email_log
                if ( $wpdb->insert($p.'s2nri_email_log', [
                    'booking_id' => $booking_id,
                    'to_email'   => $to,
                    'subject'    => $subject,
                    'type'       => 'message_notification',
                    'status'     => $email_sent ? 'sent' : 'failed',
                ]) === false ) {
                    error_log( '[S2NRI] email_log insert failed for booking ' . $booking_id . ': ' . $wpdb->last_error );
                }
            }
        }

        // Notify customer in-app
        if ( ! $is_internal && $booking['wp_user_id'] ) {
            \S2NRI\Services\NotificationService::notifyCustomer($booking['wp_user_id'], 'new_message', [
                'booking_id' => $booking_id,
                'title'      => "New message on {$booking['booking_ref']}",
                'body'       => substr($message, 0, 100) . (strlen($message) > 100 ? '…' : ''),
            ]);
        }

        $this->logAudit($booking_id, 'message_sent', $is_internal ? 'internal note' : ($send_email ? 'message+email' : 'message'));

        $msg_row = $wpdb->get_row($wpdb->prepare(
            "SELECT m.*, COALESCE(u.display_name, u.user_login,'') AS sender_name
             FROM {$p}s2nri_messages m LEFT JOIN {$p}users u ON u.ID=m.sender_id WHERE m.id=%d",
            $msg_id
        ), ARRAY_A);

        Response::json([
            'success'    => true,
            'message'    => $msg_row,
            'email_sent' => $email_sent,
            'email_error'=> $email_error,
        ]);
    }

    /**
     * POST admin/bookings/{id}/event-email
     * 5.3: One-click template-based event emails.
     * Body: { event: 'completion' | 'dispatch' | 'document_reminder' | 'status_update' }
     *
     * Maps event → template slug → resolves variables → sends email → logs.
     * Also fires WhatsApp if enabled (for completion/dispatch).
     *
     * TRACE: requireStaff → validate event → get booking + customer email →
     *        load template → substitute variables → wp_mail → log →
     *        if completion/dispatch: do_action WhatsApp hook.
     */
    public function sendEventEmail( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param('id');
        $event      = sanitize_key($req->input('event',''));

        $valid_events = ['completion','dispatch','document_reminder','status_update','application_received'];
        if ( ! in_array($event, $valid_events, true) ) {
            Response::json(['error'=>'Invalid event type.'], 422); return;
        }

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, s.name AS service_name, c.wp_user_id,
                    b.secondary_status, b.due_date, b.delivery_working_days,
                    b.quoted_amount
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
             JOIN {$p}s2nri_customers c ON c.id=b.customer_id
             WHERE b.id=%d LIMIT 1",
            $booking_id
        ), ARRAY_A);
        if ( ! $booking ) { Response::json(['error'=>'Booking not found.'], 404); return; }

        $wp_user = get_user_by('id', $booking['wp_user_id']);
        $to      = $wp_user ? $wp_user->user_email : '';
        if ( ! $to ) { Response::json(['error'=>'Customer email not found.'], 422); return; }

        $site_name  = \S2NRI\Models\Setting::get('platform_name','Services2NRI');
        $portal_url = home_url('/dashboard/bookings/'.$booking_id);
        $status_lbl = ucwords(str_replace('_',' ',$booking['status']));

        $sec_status_html = $booking['secondary_status']
            ? '<p style="font-size:13px;color:#6b7280;font-style:italic;margin:4px 0 0">'.esc_html($booking['secondary_status']).'</p>'
            : '';

        $delivery_block = '';
        if ($booking['due_date']) {
            $days = $booking['delivery_working_days'] ?? '';
            $note = $days ? " ({$days} working days — excl. Sat, Sun &amp; Govt Holidays)" : '';
            $delivery_block = '<table width="100%" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin:0 0 20px"><tr><td style="padding:14px 18px">
              <span style="font-size:13px;font-weight:600;color:#1d4ed8">⏱ Estimated Delivery: '.esc_html(date('d M Y',strtotime($booking['due_date'])).$note).'</span>
            </td></tr></table>';
        }

        $vars = [
            '{CUSTOMER_NAME}'    => $wp_user->display_name ?: 'Valued Customer',
            '{BOOKING_REF}'      => $booking['booking_ref'],
            '{SERVICE_NAME}'     => $booking['service_name'],
            '{STATUS}'           => $status_lbl,
            '{SECONDARY_STATUS}' => $sec_status_html,
            '{TIMELINE}'         => $booking['delivery_working_days'] ? $booking['delivery_working_days'].' working days' : '',
            '{DELIVERY_DATE}'    => $booking['due_date'] ? date('d M Y',strtotime($booking['due_date'])) : '',
            '{PLATFORM_NAME}'    => $site_name,
            '{DASHBOARD_URL}'    => $portal_url,
            '{TIMELINE_BLOCK}'   => $delivery_block,
            '{TC_BLOCK}'         => '',
            '{DOCUMENT_TYPE}'    => 'as requested',
            '{SERVICE_CHARGES}'  => $booking['quoted_amount'] ? '₹'.number_format((float)$booking['quoted_amount'],2) : '',
            '{SHIPPING_CHARGES}' => '₹0.00',
            '{TOTAL_AMOUNT}'     => $booking['quoted_amount'] ? '₹'.number_format((float)$booking['quoted_amount'],2) : '',
            '{SHIPPING_TYPE}'    => $booking['shipping_type'] ?? 'Domestic',
            '{SUBMITTED_DATE}'   => date('d M Y', strtotime($booking['created_at'])),
            '{QUOTE_VALID_UNTIL}'=> '',
        ];

        $slug_map = [
            'completion'           => 'completion',
            'dispatch'             => 'dispatch',
            'document_reminder'    => 'document_reminder',
            'status_update'        => 'status_update',
            'application_received' => 'application_received',
        ];
        $slug = $slug_map[$event];

        $tmpl = $wpdb->get_row($wpdb->prepare(
            "SELECT subject, body FROM {$p}s2nri_email_templates WHERE slug=%s LIMIT 1", $slug
        ), ARRAY_A);

        $subject = $tmpl
            ? str_replace(array_keys($vars), array_values($vars), $tmpl['subject'])
            : ucwords($event)." — ".$booking['booking_ref'];
        $body    = $tmpl
            ? str_replace(array_keys($vars), array_values($vars), $tmpl['body'])
            : "<p>Update on {$booking['booking_ref']}: {$status_lbl}</p>";

        // FIXED: same remove_filter closure-identity bug fixed
        // elsewhere this session.
        $html_filter_b = fn() => 'text/html';
        add_filter('wp_mail_content_type', $html_filter_b);
        $sent = wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        remove_filter('wp_mail_content_type', $html_filter_b);

        if ( $wpdb->insert($p.'s2nri_email_log',[
            'booking_id' => $booking_id,
            'to_email'   => $to,
            'subject'    => $subject,
            'type'       => $event,
            'status'     => $sent ? 'sent' : 'failed',
        ]) === false ) {
            error_log( '[S2NRI] email_log insert failed for booking ' . $booking_id . ': ' . $wpdb->last_error );
        }
        if ( $wpdb->insert($p.'s2nri_communication_log',[
            'booking_id' => $booking_id,
            'sent_by'    => get_current_user_id(),
            'to_email'   => $to,
            'subject'    => $subject,
            'body'       => $body,
            'status'     => $sent ? 'sent' : 'failed',
        ]) === false ) {
            error_log( '[S2NRI] communication_log insert failed for booking ' . $booking_id . ': ' . $wpdb->last_error );
        }

        // WhatsApp for completion/dispatch
        if ( in_array($event, ['completion','dispatch'], true) ) {
            do_action('s2nri_booking_status_changed', $booking_id, $booking['status'], [
                'booking_ref'   => $booking['booking_ref'],
                'customer_name' => $wp_user->display_name,
                'service_name'  => $booking['service_name'],
                'status'        => $booking['status'],
            ]);
        }

        $this->logAudit($booking_id, 'event_email_sent', $event);
        Response::json(['success' => $sent, 'message' => $sent ? "Email sent to {$to}" : 'Failed to send — check SMTP settings.']);
    }

    /**
     * PATCH admin/bookings/{id}/status with status='service_not_available'
     * 4.18: service_not_available is a terminal non-completion state.
     * Handled by existing updateStatus in BookingAdminController once the ENUM is updated.
     * This method handles the special notification for this status.
     *
     * GET admin/bookings/{id}/tickets?search=X
     * M-20: Ticket search on request detail page.
     */
    public function searchTickets( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param('id');
        $search     = sanitize_text_field($req->query('search',''));
        $status     = sanitize_key($req->query('status',''));

        $where = $wpdb->prepare("WHERE t.booking_id=%d", $booking_id);
        $args  = [];
        if ($search) {
            $like   = '%'.$wpdb->esc_like($search).'%';
            $where .= ' AND (t.subject LIKE %s OR tm.message LIKE %s)';
            $args[] = $like; $args[] = $like;
        }
        if ($status) {
            $where .= ' AND t.status = %s';
            $args[] = $status;
        }

        $sql = "SELECT t.*, b.booking_ref,
                       (SELECT COUNT(*) FROM {$p}s2nri_ticket_messages WHERE ticket_id=t.id) AS message_count
                FROM {$p}s2nri_tickets t
                LEFT JOIN {$p}s2nri_bookings b ON b.id=t.booking_id
                LEFT JOIN {$p}s2nri_ticket_messages tm ON tm.ticket_id=t.id
                {$where} GROUP BY t.id ORDER BY t.updated_at DESC LIMIT 50";
        $rows = $args
            ? $wpdb->get_results($wpdb->prepare($sql,...$args), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        Response::json(['tickets' => $rows ?: []]);
    }

    /** POST admin/bookings/{id}/tickets — create a ticket linked to a booking */
    public function createTicket( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param('id');
        $category   = sanitize_text_field( $req->input('category', 'General') );
        $notes      = sanitize_textarea_field( $req->input('notes', '') );

        // Get customer_id from booking
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT customer_id, booking_ref FROM {$p}s2nri_bookings WHERE id=%d LIMIT 1", $booking_id
        ), ARRAY_A );
        if ( ! $booking ) { Response::json(['error'=>'Booking not found.'], 404); return; }

        $ticket_insert = $wpdb->insert( $p . 's2nri_tickets', [
            'customer_id' => $booking['customer_id'],
            'booking_id'  => $booking_id,
            'subject'     => "Support request for {$booking['booking_ref']} — {$category}",
            'category'    => $category,
            'status'      => 'open',
            'notes'       => $notes,
            'created_at'  => current_time('mysql'),
            'updated_at'  => current_time('mysql'),
        ] );
        // CHECKED (was previously unchecked, and the prior $ticket_id
        // variable held $wpdb->insert()'s boolean return value, not an
        // actual id — traced and confirmed it was never used downstream,
        // so that naming issue wasn't itself causing wrong behavior; the
        // real bug is the same as elsewhere: insert_id does not reset to
        // 0 on failure, so a failed insert here would previously cascade
        // a stale id into the message insert below, the audit log, AND
        // the success response's ticket_id).
        if ( $ticket_insert === false ) {
            error_log( '[S2NRI] Ticket insert failed for booking ' . $booking_id . ': ' . $wpdb->last_error );
            Response::json(['error'=>'Failed to create ticket. Please try again.'],500); return;
        }
        $new_ticket_id = (int) $wpdb->insert_id;

        if ( $notes ) {
            if ( $wpdb->insert( $p . 's2nri_ticket_messages', [
                'ticket_id'   => $new_ticket_id,
                'sender_id'   => $this->user['wp_id'],
                'sender_type' => 'staff',
                'message'     => $notes,
                'created_at'  => current_time('mysql'),
            ] ) === false ) {
                error_log( '[S2NRI] Ticket message insert failed for ticket ' . $new_ticket_id . ': ' . $wpdb->last_error );
                // Non-fatal — the ticket itself is already safely created and checked above.
            }
        }

        $this->logAudit( $booking_id, 'ticket_created', '', (string) $new_ticket_id );
        Response::json(['success'=>true, 'ticket_id'=>$new_ticket_id], 201);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// AuditTrailController
// Enhanced audit trail — per-booking full history and system-wide activity feed
// ══════════════════════════════════════════════════════════════════════════════

class AuditTrailController extends BaseController {

    /**
     * GET admin/bookings/{id}/audit-trail
     * Returns full activity timeline for a booking:
     * - All status changes with old/new values and timestamps
     * - All messages sent (internal + external) with sender
     * - All emails sent (from email_log + communication_log)
     * - All document uploads
     * - All quotes created/approved/rejected
     * - All payment events
     * - All assignments
     *
     * Returns unified timeline sorted by created_at DESC.
     *
     * TRACE: requireStaff → run 7 queries → merge → sort by timestamp → return.
     * EDGE CASES: booking not found → 404. Empty history → empty array.
     */
    public function bookingTimeline( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param('id');

        $booking = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}s2nri_bookings WHERE id=%d", $booking_id));
        if ( ! $booking ) { Response::json(['error'=>'Booking not found.'], 404); return; }

        $timeline = [];

        // 1. Audit log entries
        $audit_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT al.action, al.old_value, al.new_value, al.details, al.created_at,
                    COALESCE(u.display_name, u.user_login, 'System') AS actor
             FROM {$p}s2nri_audit_log al
             LEFT JOIN {$p}users u ON u.ID=al.user_id
             WHERE al.booking_id=%d ORDER BY al.created_at DESC LIMIT 50",
            $booking_id
        ), ARRAY_A);
        foreach ($audit_rows as $r) {
            $timeline[] = [
                'type'      => 'audit',
                'icon'      => '🔄',
                'label'     => ucwords(str_replace('_',' ',$r['action'])),
                'detail'    => $r['old_value'] && $r['new_value'] ? "{$r['old_value']} → {$r['new_value']}" : ($r['details'] ?? ''),
                'actor'     => $r['actor'],
                'timestamp' => $r['created_at'],
            ];
        }

        // 2. Messages
        $msg_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.message, m.sender_type, m.is_internal, m.is_email_sent, m.created_at,
                    COALESCE(u.display_name, u.user_login, 'Customer') AS sender_name
             FROM {$p}s2nri_messages m LEFT JOIN {$p}users u ON u.ID=m.sender_id
             WHERE m.booking_id=%d ORDER BY m.created_at DESC LIMIT 30",
            $booking_id
        ), ARRAY_A);
        foreach ($msg_rows as $r) {
            $icon  = $r['is_internal'] ? '📝' : ($r['is_email_sent'] ? '✉️' : '💬');
            $label = $r['is_internal'] ? 'Internal Note' : ($r['is_email_sent'] ? 'Message + Email' : 'Message');
            $timeline[] = [
                'type'      => 'message',
                'icon'      => $icon,
                'label'     => $label,
                'detail'    => substr($r['message'],0,120).(strlen($r['message'])>120?'…':''),
                'actor'     => $r['sender_name'],
                'timestamp' => $r['created_at'],
            ];
        }

        // 3. Email log (sent emails)
        $email_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT subject, type, status, created_at FROM {$p}s2nri_email_log WHERE booking_id=%d ORDER BY created_at DESC LIMIT 20",
            $booking_id
        ), ARRAY_A);
        foreach ($email_rows as $r) {
            $timeline[] = [
                'type'      => 'email',
                'icon'      => $r['status']==='sent' ? '📧' : '❌',
                'label'     => 'Email: '.ucwords(str_replace('_',' ',$r['type'])),
                'detail'    => $r['subject'],
                'actor'     => 'System',
                'timestamp' => $r['created_at'],
                'status'    => $r['status'],
            ];
        }

        // 4. Document uploads
        $doc_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.doc_type, d.file_name, d.is_from_staff, d.created_at,
                    COALESCE(u.display_name, u.user_login, '') AS uploader
             FROM {$p}s2nri_documents d LEFT JOIN {$p}users u ON u.ID=d.uploaded_by
             WHERE d.booking_id=%d ORDER BY d.created_at DESC LIMIT 20",
            $booking_id
        ), ARRAY_A);
        foreach ($doc_rows as $r) {
            $timeline[] = [
                'type'      => 'document',
                'icon'      => '📎',
                'label'     => $r['is_from_staff'] ? 'Staff Document Uploaded' : 'Customer Document Uploaded',
                'detail'    => "{$r['doc_type']} — {$r['file_name']}",
                'actor'     => $r['uploader'],
                'timestamp' => $r['created_at'],
            ];
        }

        // 5. Quotes
        $quote_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT q.amount, q.status, q.created_at, q.approved_at, q.rejected_at,
                    COALESCE(u.display_name,'Staff') AS created_by_name
             FROM {$p}s2nri_quotes q LEFT JOIN {$p}users u ON u.ID=q.created_by
             WHERE q.booking_id=%d ORDER BY q.created_at DESC LIMIT 10",
            $booking_id
        ), ARRAY_A);
        foreach ($quote_rows as $r) {
            $timeline[] = [
                'type'      => 'quote',
                'icon'      => '💰',
                'label'     => 'Quote Created',
                'detail'    => '₹'.number_format((float)$r['amount'],2).' — '.$r['status'],
                'actor'     => $r['created_by_name'],
                'timestamp' => $r['created_at'],
            ];
        }

        // Sort all events newest first
        usort($timeline, fn($a,$b) => strcmp($b['timestamp'], $a['timestamp']));

        Response::json(['timeline' => $timeline, 'total' => count($timeline)]);
    }

    /**
     * GET admin/activity-feed?limit=30
     * System-wide activity feed for admin dashboard sidebar.
     * Returns last N actions across all bookings.
     */
    public function activityFeed( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p     = $wpdb->prefix;
        $limit = min((int) $req->query('limit','30'), 100);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT al.action, al.old_value, al.new_value, al.created_at,
                    b.booking_ref, b.id AS booking_id,
                    COALESCE(u.display_name, u.user_login, 'System') AS actor
             FROM {$p}s2nri_audit_log al
             LEFT JOIN {$p}s2nri_bookings b ON b.id=al.booking_id
             LEFT JOIN {$p}users u ON u.ID=al.user_id
             ORDER BY al.created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        Response::json(['feed' => $rows ?: []]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// SystemSettingsController
// Extended settings for Phase 3 features: overdue threshold, WhatsApp templates,
// service_not_available notification text, SMTP diagnostics
// ══════════════════════════════════════════════════════════════════════════════

class SystemSettingsController extends BaseController {

    /**
     * GET admin/settings/phase3
     * Returns all Phase 3 configurable settings in a structured grouped format.
     *
     * Groups:
     *   whatsapp: apikey, notify_numbers, event templates
     *   operations: overdue_threshold_days, service_unavailable_message
     *   smtp: all SMTP fields (re-exposed for convenience)
     */
    public function getPhase3( Request $req ): void {
        $this->requireManager();
        $all = \S2NRI\Models\Setting::getAll();

        $phase3_keys = [
            'whatsapp' => [
                'whatsapp_callmebot_apikey',
                'whatsapp_notify_numbers',
                'whatsapp_template_booking_submitted',
                'whatsapp_template_status_changed',
                'whatsapp_template_payment_received',
                'whatsapp_template_quote_approved',
            ],
            'operations' => [
                'overdue_threshold_days',
                'service_unavailable_message',
                'auto_status_notifications',
            ],
        ];

        $result = [];
        foreach ($phase3_keys as $group => $keys) {
            foreach ($keys as $key) {
                $result[$group][$key] = $all[$key]['value'] ?? '';
            }
        }

        Response::json(['settings' => $result]);
    }

    /**
     * GET admin/smtp-test
     * Sends a test email to the admin email address.
     * Returns delivery status.
     */
    public function smtpTest( Request $req ): void {
        $this->requireManager();
        $to      = sanitize_email($req->query('to', get_option('admin_email','')));
        $site_name = \S2NRI\Models\Setting::get('platform_name','Services2NRI');
        if ( ! is_email($to) ) { Response::json(['error'=>'Valid email required.'], 422); return; }
        // FIXED: remove_filter() requires the EXACT SAME closure reference
        // that was passed to add_filter() — two separately-written
        // `fn() => 'text/html'` closures are NOT the same object in PHP,
        // even with identical code, so the original remove_filter() call
        // silently failed every time. This left the HTML content-type
        // filter permanently attached for the rest of the request,
        // silently affecting the content-type of any OTHER email sent
        // afterward from any other code path in the same request.
        $html_filter = fn() => 'text/html';
        add_filter('wp_mail_content_type', $html_filter);
        $sent = wp_mail($to, "[{$site_name}] SMTP Test Email", "<p>SMTP is configured correctly. This test email was sent from <strong>{$site_name}</strong> at ".date('d M Y H:i:s T').".</p>", ['Content-Type: text/html; charset=UTF-8']);
        remove_filter('wp_mail_content_type', $html_filter);
        Response::json(['success' => $sent, 'to' => $to, 'message' => $sent ? "Test email sent to {$to}" : 'Failed. Check SMTP settings in the Settings page.']);
    }
}

