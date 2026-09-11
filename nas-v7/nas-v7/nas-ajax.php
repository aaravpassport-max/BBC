<?php
/**
 * NAS Dedicated AJAX Handler
 *
 * URL: /wp-content/plugins/newspaper-ads-saas/nas-ajax.php
 *
 * Why this file exists:
 * The CDN (in2.cdn-alpha.com) blocks POST requests to /wp-admin/admin-ajax.php.
 * WordPress page cache plugins may serve cached responses for /?nas_ajax=1.
 * This dedicated file is a direct PHP endpoint — never cached, always executed.
 *
 * How it works:
 * 1. Loads WordPress bootstrap directly
 * 2. Defines DOING_AJAX so wp_send_json_success()/die() work correctly
 * 3. Verifies the action is in the NAS allowlist
 * 4. Dispatches via WordPress hook system (wp_ajax_{action})
 */

// Security: ensure WordPress is loaded
if ( ! defined('ABSPATH') ) {
    // Find wp-load.php by walking up from this file's location
    $dir = dirname(__FILE__);
    $wp_load = '';
    for ( $i = 0; $i < 10; $i++ ) {
        if ( file_exists( $dir . '/wp-load.php' ) ) {
            $wp_load = $dir . '/wp-load.php';
            break;
        }
        $dir = dirname($dir);
    }
    if ( ! $wp_load ) {
        http_response_code(500);
        echo '{"success":false,"data":{"message":"Could not locate WordPress"}}';
        exit;
    }

    // Define DOING_AJAX BEFORE loading WordPress so all hooks see it
    define( 'DOING_AJAX', true );
    require_once $wp_load;
}

// Kill any output buffering that might corrupt JSON
while ( ob_get_level() > 0 ) ob_end_clean();

// Response headers
nocache_headers();
header( 'Content-Type: application/json; charset=utf-8' );
header( 'X-Robots-Tag: noindex' );

// Validate request
if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
    http_response_code(405);
    echo wp_json_encode( ['success'=>false,'data'=>['message'=>'POST required']] );
    exit;
}

$action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
if ( ! $action ) {
    http_response_code(400);
    echo wp_json_encode( ['success'=>false,'data'=>['message'=>'No action specified']] );
    exit;
}

// Allowlist — only NAS actions permitted
$allowed = [
    // Client dashboard
    'nas_get_dashboard_stats', 'nas_get_client_bookings', 'nas_get_booking_detail',
    'nas_get_booking_timeline', 'nas_get_profile', 'nas_update_profile',
    'nas_get_wallet', 'nas_get_my_tickets', 'nas_get_ticket_detail',
    'nas_reply_ticket', 'nas_send_message', 'nas_get_messages',
    'nas_get_notifications', 'nas_mark_notification_read',
    'nas_submit_support_ticket', 'nas_upload_material', 'nas_upload_document',
    'nas_get_materials', 'nas_update_client_profile', 'nas_get_client_profile',
    'nas_cancel_booking',
    // Booking wizard
    'nas_get_newspapers', 'nas_get_categories', 'nas_get_cities',
    'nas_get_editions', 'nas_get_rates', 'nas_get_sample_ads',
    'nas_pwa_wizard_submit', 'nas_pwa_wizard_quote',
    // PWA
    'nas_pwa_home', 'nas_pwa_browse', 'nas_pwa_bookings',
    'nas_pwa_vendor_bookings', 'nas_pwa_vendor_accept', 'nas_pwa_vendor_reject',
    'nas_pwa_vendor_bookings_stats', 'nas_pwa_vendor_profile', 'nas_pwa_save_vendor_profile',
    'nas_pwa_upload_proof', 'nas_pwa_mark_published',
    'nas_pwa_save_settings', 'nas_pwa_get_settings',
    'nas_pwa_track', 'nas_pwa_push_subscribe_v2',
    'nas_pwa_send_push_campaign', 'nas_pwa_get_push_campaigns',
    'nas_pwa_analytics_summary', 'nas_pwa_init_payment', 'nas_pwa_rate_card',
    'nas_pwa_validate_coupon', 'nas_pwa_register', 'nas_pwa_search',
    // Payment
    'nas_create_payment_order', 'nas_verify_payment',
    // Public
    'nas_submit_contact', 'nas_get_faqs', 'nas_get_blog_posts', 'nas_get_blog_post',
    'nas_subscribe_newsletter', 'nas_create_client_account_from_booking',
    'nas_track_order',
    // Admin
    'nas_get_settings', 'nas_save_settings', 'nas_get_all_clients',
    'nas_save_newspaper', 'nas_delete_newspaper', 'nas_export_csv',
    'nas_toggle_feature_flag', 'nas_get_feature_flags',
    'nas_get_assigned_bookings', 'nas_get_today_tasks', 'nas_mark_task_done',
    'nas_update_booking_status', 'nas_get_all_bookings',
    'nas_get_pending_bookings', 'nas_approve_booking', 'nas_reject_booking',
    'nas_bulk_booking_action', 'nas_get_unread_count',
    'nas_admin_update_status', 'nas_admin_update_client',
    'nas_admin_get_client_wallet', 'nas_admin_credit_wallet', 'nas_admin_debit_wallet',
    'nas_admin_get_client_notes', 'nas_admin_save_client_notes',
    'nas_admin_send_client_message', 'nas_admin_export_bookings_csv',
    'nas_admin_get_calendar_bookings', 'nas_admin_send_password_reset',
    'nas_admin_vendor_payout_bookings', 'nas_admin_mark_vendor_all_paid',
    'nas_admin_record_vendor_payment', 'nas_admin_vendor_statement',
    'nas_get_branding', 'nas_save_branding',
    'nas_get_email_templates', 'nas_get_email_template', 'nas_save_email_template',
    'nas_download_invoice', 'nas_generate_invoice',
];

if ( ! in_array( $action, $allowed, true ) ) {
    http_response_code(403);
    echo wp_json_encode( ['success'=>false,'data'=>['message'=>'Action not permitted: '.$action]] );
    exit;
}

// Dispatch: logged-in handler first, then nopriv
$hook = 'wp_ajax_' . $action;
if ( has_action( $hook ) ) {
    do_action( $hook );
} else {
    $hook_nopriv = 'wp_ajax_nopriv_' . $action;
    if ( has_action( $hook_nopriv ) ) {
        do_action( $hook_nopriv );
    } else {
        http_response_code(404);
        echo wp_json_encode( ['success'=>false,'data'=>['message'=>'No handler registered for: '.$action]] );
    }
}
exit;
