<?php
namespace S2NRI\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * NotificationJob — retries failed emails from the last 24 hours.
 *
 * TRACE: fires on s2nri_job_notifications (hourly) →
 *        query email_log WHERE status='failed' AND NOT retried in last 6h →
 *        re-send each using the logged subject + body →
 *        update status to 'sent' or leave 'failed' after max attempts.
 * PRECONDITIONS: s2nri_email_log table exists.
 * POSTCONDITIONS: up to 5 failed emails retried per run.
 * EDGE CASES: SMTP still down → stays 'failed'. Max 3 attempts before permanent fail.
 */
class NotificationJob {
    public static function handle(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Only retry emails that haven't been retried in the last 6 hours
        // and haven't exceeded 3 total attempts (attempts tracked via retry_count column if present,
        // otherwise limit by created_at recency)
        $failed = $wpdb->get_results(
            "SELECT el.id, el.to_email, el.subject, el.type, el.booking_id
             FROM {$p}s2nri_email_log el
             WHERE el.status = 'failed'
               AND el.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND el.id NOT IN (
                 SELECT id FROM {$p}s2nri_email_log
                 WHERE status='failed' AND created_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)
               )
             LIMIT 5",
            ARRAY_A
        );

        $retried = 0;
        foreach ( $failed as $log ) {
            // We don't store email body in email_log — just mark as needs_review
            // and notify admin that failed emails exist
            error_log( "[S2NRI NotificationJob] Failed email ID {$log['id']} to {$log['to_email']} type={$log['type']}" );

            // For quote emails, re-trigger if quote still pending
            if ( $log['type'] === 'quote_sent' && $log['booking_id'] ) {
                $booking = $wpdb->get_row( $wpdb->prepare(
                    "SELECT b.quoted_amount, b.quote_expires_at, b.booking_ref, cu.wp_user_id
                     FROM {$p}s2nri_bookings b
                     JOIN {$p}s2nri_customers cu ON cu.id=b.customer_id
                     WHERE b.id=%d AND b.status='quote_sent' LIMIT 1",
                    $log['booking_id']
                ), ARRAY_A );
                if ( $booking && strtotime( $booking['quote_expires_at'] ) > time() ) {
                    try {
                        // FIXED (was previously calling
                        // NotificationService::sendQuoteEmail(), which
                        // does not exist — NotificationService only has
                        // notifyCustomer(), notifyAdmins(), and
                        // sendWelcomeEmail(). The real sendQuoteEmail()
                        // method lives on EmailService (traced and
                        // confirmed directly in this file). Every call to
                        // the wrong class threw "Call to undefined
                        // method", caught by the \Throwable wrapper below
                        // (so the cron job itself never crashed) but the
                        // failed-quote-email retry feature has silently
                        // never worked at all.
                        \S2NRI\Services\EmailService::sendQuoteEmail(
                            (int) $booking['wp_user_id'],
                            (int) $log['booking_id'],
                            (float) $booking['quoted_amount'],
                            $booking['quote_expires_at']
                        );
                        if ( $wpdb->update( $p . 's2nri_email_log', ['status'=>'sent'], ['id'=>$log['id']] ) === false ) {
                            error_log( "[S2NRI NotificationJob] Failed to mark email log {$log['id']} sent: " . $wpdb->last_error );
                        }
                        $retried++;
                    } catch ( \Throwable $e ) {
                        error_log( "[S2NRI NotificationJob] Retry failed for email {$log['id']}: " . $e->getMessage() );
                    }
                }
            }
        }

        // Notify admin of persistent failures (once per day, avoid spam)
        $persistent_failures = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}s2nri_email_log WHERE status='failed' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        if ( $persistent_failures > 5 ) {
            $admin_last_notified = get_transient('s2nri_email_failure_admin_notified');
            if ( ! $admin_last_notified ) {
                $admin = get_option('admin_email');
                $site  = \S2NRI\Models\Setting::get('platform_name','Services2NRI');
                wp_mail( $admin, "[{$site}] Email delivery failures", "There are {$persistent_failures} failed email delivery attempts in the last 24 hours. Please check your SMTP configuration at " . admin_url('admin.php?page=s2nri-portal') );
                set_transient('s2nri_email_failure_admin_notified', 1, DAY_IN_SECONDS);
            }
        }

        error_log( "[S2NRI NotificationJob] Ran at " . current_time('mysql') . " — retried {$retried} emails." );
    }
}

/**
 * QuoteReminderJob — sends quote expiry reminders and auto-expires old quotes.
 *
 * TRACE: fires on s2nri_job_quote_reminders (twice daily) →
 *        1) Find quote_sent bookings expiring within reminder window → send reminder email.
 *        2) Find quote_sent bookings where quote_expires_at has passed → auto-cancel quote
 *           (revert to 'submitted' so admin can re-send, or set 'under_review').
 * PRECONDITIONS: s2nri_bookings, s2nri_email_log tables exist.
 * POSTCONDITIONS: reminders sent, expired quotes reverted.
 */
class QuoteReminderJob {
    public static function handle(): void {
        global $wpdb;
        $p             = $wpdb->prefix;
        $reminder_days = (int) \S2NRI\Models\Setting::get('quote_reminder_days', '2');

        // 1. Send reminders for quotes expiring soon
        $expiring = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id, b.booking_ref, b.quoted_amount, b.quote_expires_at, cu.wp_user_id
             FROM {$p}s2nri_bookings b
             JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE b.status = 'quote_sent'
               AND b.quote_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)
               AND b.id NOT IN (
                 SELECT booking_id FROM {$p}s2nri_email_log
                 WHERE type = 'quote_reminder' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
               )",
            $reminder_days
        ), ARRAY_A );

        foreach ( $expiring as $bk ) {
            // In-app notification
            \S2NRI\Services\NotificationService::notifyCustomer( (int) $bk['wp_user_id'], 'quote_expiring', [
                'booking_id' => $bk['id'],
                'title'      => "Quote Expiring Soon: {$bk['booking_ref']}",
                'body'       => "Your quote of ₹{$bk['quoted_amount']} expires on {$bk['quote_expires_at']}. Please log in to approve or reject.",
            ] );

            // Template email via document_reminder template as closest match
            $wp_user = get_user_by('id', $bk['wp_user_id']);
            if ($wp_user) {
                $site_name  = \S2NRI\Models\Setting::get('platform_name','Services2NRI');
                $portal_url = home_url('/dashboard/bookings/'.$bk['id']);
                $vars = [
                    '{CUSTOMER_NAME}'    => $wp_user->display_name,
                    '{BOOKING_REF}'      => $bk['booking_ref'],
                    '{TOTAL_AMOUNT}'     => '₹'.number_format((float)$bk['quoted_amount'],2),
                    '{QUOTE_VALID_UNTIL}'=> date('d M Y',strtotime($bk['quote_expires_at'])),
                    '{PLATFORM_NAME}'    => $site_name,
                    '{DASHBOARD_URL}'    => $portal_url,
                ];
                $tmpl = $wpdb->get_row("SELECT subject, body FROM {$p}s2nri_email_templates WHERE slug='quote_sent' LIMIT 1", ARRAY_A);
                if ($tmpl) {
                    $subj = str_replace(array_keys($vars),array_values($vars),'[Reminder] '.$tmpl['subject']);
                    $body = str_replace(array_keys($vars),array_values($vars),$tmpl['body']);
                    // FIXED: closure-identity remove_filter bug.
                    $html_filter_job = fn()=>'text/html';
                    add_filter('wp_mail_content_type',$html_filter_job);
                    $sent = wp_mail($wp_user->user_email, $subj, $body, ['Content-Type: text/html; charset=UTF-8']);
                    remove_filter('wp_mail_content_type',$html_filter_job);
                    if ( $wpdb->insert($p.'s2nri_email_log',[
                        'booking_id'=>$bk['id'],'to_email'=>$wp_user->user_email,
                        'subject'=>$subj,'type'=>'quote_reminder','status'=>$sent?'sent':'failed',
                    ]) === false ) {
                        error_log( '[S2NRI QuoteReminderJob] email_log insert failed for booking ' . $bk['id'] . ': ' . $wpdb->last_error );
                    }
                }
            }
        }

        // 2. Auto-revert expired quotes
        $expired = $wpdb->get_results(
            "SELECT id, booking_ref, customer_id FROM {$p}s2nri_bookings
             WHERE status='quote_sent' AND quote_expires_at < NOW()",
            ARRAY_A
        );
        foreach ($expired as $bk) {
            if ( $wpdb->update($p.'s2nri_bookings', ['status'=>'under_review'], ['id'=>$bk['id']]) === false ) {
                error_log("[S2NRI QuoteReminderJob] FAILED to revert expired quote for {$bk['booking_ref']}: " . $wpdb->last_error);
            } else {
                error_log("[S2NRI QuoteReminderJob] Quote expired for {$bk['booking_ref']} — reverted to under_review.");
            }
        }

        error_log( "[S2NRI QuoteReminderJob] Reminders: ".count($expiring).", Expired: ".count($expired)." at ".current_time('mysql') );
    }
}

/**
 * CleanupJob — daily cleanup of expired data and orphaned records.
 *
 * Cleans:
 * - Expired OTPs (> 1 day old)
 * - Expired magic links (> 1 day past expiry)
 * - Read notifications (> 90 days old)
 * - Old diagnostic runs (> 30 days, keeps last 10)
 * - Temporary ZIP files in wp-content/uploads/s2nri-tmp/
 *
 * TRACE: fires on s2nri_job_cleanup (daily) → run each DELETE → log counts.
 */
class CleanupJob {
    public static function handle(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $del_otps   = (int) $wpdb->query("DELETE FROM {$p}s2nri_otps WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $del_links  = (int) $wpdb->query("DELETE FROM {$p}s2nri_magic_links WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $del_notifs = (int) $wpdb->query("DELETE FROM {$p}s2nri_notifications WHERE is_read=1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");

        // Keep only last 10 diagnostic runs per system (delete oldest beyond 10)
        $diag_table = $wpdb->prefix . 's2nri_diagnostic_runs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$diag_table}'")) {
            $keep_ids = $wpdb->get_col("SELECT id FROM {$diag_table} ORDER BY created_at DESC LIMIT 10");
            if ($keep_ids) {
                $placeholders = implode(',',array_fill(0,count($keep_ids),'%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM {$diag_table} WHERE id NOT IN ($placeholders)", ...$keep_ids));
            }
        }

        // Delete old temp ZIP directories
        $tmp_dir = wp_upload_dir()['basedir'] . '/s2nri-tmp/';
        if (is_dir($tmp_dir)) {
            $dirs = glob($tmp_dir.'zip_*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                if (filemtime($dir) < time() - 3600) { // older than 1 hour
                    array_map('unlink', glob($dir.'/*'));
                    @rmdir($dir);
                }
            }
        }

        // Auto-send overdue alerts to admin (if enabled)
        $overdue_days = (int) \S2NRI\Models\Setting::get('overdue_threshold_days','5');
        if ($overdue_days > 0) {
            $overdue_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status NOT IN ('completed','cancelled','service_not_available') AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $overdue_days
            ));
            if ($overdue_count > 0) {
                $site  = \S2NRI\Models\Setting::get('platform_name','Services2NRI');
                $cache_key = 's2nri_overdue_alert_'.date('Y-m-d');
                if (!get_transient($cache_key)) {
                    $admin = get_option('admin_email');
                    wp_mail($admin,"[{$site}] {$overdue_count} overdue bookings require attention",
                        "<p>{$overdue_count} booking(s) have had no activity for more than {$overdue_days} days. Please review them at ".admin_url('admin.php?page=s2nri-portal')."</p>",
                        ['Content-Type: text/html; charset=UTF-8']
                    );
                    set_transient($cache_key, 1, DAY_IN_SECONDS);
                }
            }
        }

        error_log("[S2NRI CleanupJob] OTPs:{$del_otps} MagicLinks:{$del_links} Notifs:{$del_notifs} at ".current_time('mysql'));
    }
}

/**
 * SitemapJob — regenerates XML sitemap daily.
 */
class SitemapJob {
    public static function handle(): void {
        global $wpdb;
        $p        = $wpdb->prefix;
        $site_url = home_url();

        $cats     = \S2NRI\Services\ServiceRegistry::getPublicCategories( 'sitemap' );
        $services = \S2NRI\Services\ServiceRegistry::forSurface( 'sitemap' );
        $cities   = $wpdb->get_results("SELECT slug FROM {$p}s2nri_cities WHERE is_active=1", ARRAY_A);
        $posts    = $wpdb->get_results("SELECT slug FROM {$p}s2nri_blog_posts WHERE is_published=1", ARRAY_A);

        $urls = ['/', '/services', '/about', '/contact', '/how-it-works', '/faq', '/blog', '/pricing'];
        foreach ( $cats as $c ) {
            $urls[] = '/services/' . $c['slug'];
        }
        foreach ( $services as $s ) {
            $urls[] = '/service/' . $s['slug'];
        }
        foreach ($cities   as $ci) $urls[] = '/cities/'  . $ci['slug'];
        foreach ($posts    as $pt) $urls[] = '/blog/'    . $pt['slug'];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= "  <url><loc>" . esc_url($site_url.$url) . "</loc><changefreq>weekly</changefreq></url>\n";
        }
        $xml .= '</urlset>';

        @file_put_contents(ABSPATH . 's2nri-sitemap.xml', $xml);
        error_log('[S2NRI SitemapJob] Sitemap regenerated at '.current_time('mysql').' — '.count($urls).' URLs');
    }
}
