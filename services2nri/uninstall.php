<?php
/**
 * Uninstall handler — runs when plugin is deleted from WP admin.
 * Removes all plugin data: tables, options, roles, scheduled events.
 *
 * WARNING: This is irreversible. All data will be lost.
 * Data is only deleted if the 's2nri_delete_data_on_uninstall' setting is '1'.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Safety gate: only delete data if explicitly enabled in settings
$delete_data = get_option( 's2nri_delete_data_on_uninstall', '0' );
if ( $delete_data !== '1' ) {
    return; // Preserve data by default — admin must opt in
}

// Remove all scheduled hooks
$hooks = [
    's2nri_job_notifications',
    's2nri_job_quote_reminders',
    's2nri_job_cleanup',
    's2nri_job_sitemap',
];
foreach ( $hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
}

// Drop all tables in dependency order (children before parents)
$tables = [
    // Phase 4 new
    's2nri_field_options',
    's2nri_form_fields',
    // Phase 2 new
    's2nri_communication_log',
    // Phase 1 new
    's2nri_magic_links',
    's2nri_email_templates',
    's2nri_holidays',
    's2nri_quick_replies',
    's2nri_vendors',
    's2nri_request_types',
    's2nri_ref_counter',
    // Phase 0 base tables — children first
    's2nri_ticket_messages',
    's2nri_tickets',
    's2nri_reviews',
    's2nri_page_views',
    's2nri_notifications',
    's2nri_audit_log',
    's2nri_diagnostics_log',
    's2nri_diagnostic_runs',
    's2nri_email_log',
    's2nri_otps',
    's2nri_payments',
    's2nri_messages',
    's2nri_documents',
    's2nri_quotes',
    's2nri_bookings',
    's2nri_staff',
    's2nri_customers',
    // Content tables
    's2nri_service_sections',
    's2nri_services',
    's2nri_categories',
    's2nri_cities',
    's2nri_blog_posts',
    's2nri_media',
    's2nri_pricing_plans',
    's2nri_faqs',
    's2nri_testimonials',
    // ADDED: s2nri_contact_messages was missing from this list — added
    // during this session's audit (contact form durability fix). Without
    // this, deleting the plugin with data-cleanup enabled would leave
    // this table permanently orphaned in the database.
    's2nri_contact_messages',
    // Settings last
    's2nri_settings',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
}

// Remove WP options
foreach ( [ 's2nri_db_version', 's2nri_activated_at', 's2nri_opcache_cleared', 's2nri_delete_data_on_uninstall' ] as $opt ) {
    delete_option( $opt );
}

// Clear all s2nri user meta
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 's2nri_%'" );

// Remove custom roles
foreach ( [ 's2nri_customer', 's2nri_agent', 's2nri_manager', 's2nri_finance' ] as $role ) {
    remove_role( $role );
}

// Remove generated files
@unlink( ABSPATH . 's2nri-sitemap.xml' );
@unlink( ABSPATH . 'sw.js' );
@unlink( ABSPATH . 'manifest.json' );

// Remove temp ZIP directory
$tmp_dir = wp_upload_dir()['basedir'] . '/s2nri-tmp/';
if ( is_dir($tmp_dir) ) {
    array_map( 'unlink', glob($tmp_dir.'*/*') ?: [] );
    array_map( 'rmdir',  glob($tmp_dir.'*')   ?: [] );
    @rmdir( $tmp_dir );
}

// Clear all transients
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_s2nri_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_s2nri_%'" );
