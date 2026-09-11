<?php
/**
 * NAS Plugin Uninstall Handler
 *
 * Registered via register_uninstall_hook() in newspaper-ads-saas.php.
 * Runs when admin clicks Delete in WordPress plugin management.
 *
 * What is removed: all 37 nas_ custom tables, all nas_ options, all scheduled cron events,
 *                  all uploaded ad material files in wp-content/uploads/nas-materials/
 * What is preserved: WordPress user accounts created for clients/vendors (they are WP users
 *                    that may have other roles/data; deleting them is irreversible and outside scope)
 *
 * Safety gate: only runs when WP_UNINSTALL_PLUGIN is defined (set by WordPress core).
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// TRACE: Triggered by WP core when admin clicks Delete on this plugin.
// Precondition: WP_UNINSTALL_PLUGIN defined (WordPress core safety gate, checked above).
// Postcondition: all 37 nas_ tables dropped; all nas_ options deleted; all 4 cron events
//               cleared; nas-materials/ upload folder recursively deleted.
// Edge cases: table may not exist (DROP TABLE IF EXISTS handles it);
//             upload dir may not exist (is_dir() guard handles it);
//             cron hook may not be scheduled (wp_clear_scheduled_hook() is safe to call).
// ── 1. Drop all NAS custom tables (order respects FK-like dependencies) ──────
$tables = [
    'nas_audit_log',
    'nas_error_log',
    'nas_ticket_replies',
    'nas_support_tickets',
    'nas_ad_materials',
    'nas_messages',
    'nas_payments',
    'nas_invoices',
    'nas_wallet_transactions',
    'nas_wallet',
    'nas_queue',
    'nas_notifications',
    'nas_analytics',
    'nas_ai_logs',
    'nas_followups',
    'nas_quotations',
    'nas_price_history',
    'nas_bookings',
    'nas_rate_cards',
    'nas_combo_offers',
    'nas_sample_ads',
    'nas_ad_sizes',
    'nas_coupons',
    'nas_newspaper_blackout_dates',
    'nas_quick_replies',
    'nas_templates',
    'nas_email_templates',
    'nas_faqs',
    'nas_blog_posts',
    'nas_contact_submissions',
    'nas_job_postings',
    'nas_job_applications',
    'nas_feature_flags',
    'nas_seo_pages',
    'nas_city_page_meta',
    'nas_categories',
    'nas_newspapers',
    'nas_cities',
    'nas_vendors',
    'nas_clients',
    'nas_settings',
];

foreach ( $tables as $table ) {
    $full_name = $wpdb->prefix . $table;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `{$full_name}`" );
}

// ── 2. Delete all nas_ options ────────────────────────────────────────────────
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE 'nas_%'" );
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '_transient_nas_%'" );
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '_transient_timeout_nas_%'" );

// ── 3. Clear all NAS scheduled cron events ────────────────────────────────────
$cron_hooks = [
    'nas_queue_worker',
    'nas_daily_tasks',
    'nas_sla_check',
    'nas_error_log_prune',
];
foreach ( $cron_hooks as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
    }
    wp_clear_scheduled_hook( $hook );
}

// ── 4. Remove uploaded material files ─────────────────────────────────────────
$upload_dir   = wp_upload_dir();
$nas_material = trailingslashit( $upload_dir['basedir'] ) . 'nas-materials';
if ( is_dir( $nas_material ) ) {
    // Recursively delete directory
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $nas_material, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $file ) {
        $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
    }
    rmdir( $nas_material );
}
