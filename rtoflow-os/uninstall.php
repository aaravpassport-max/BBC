<?php
/**
 * RTOFLOW OS — Uninstall Handler
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes ALL plugin data: custom tables, roles, options, transients, and pages.
 *
 * P1-WP-002 FIX: this file was missing — GDPR violation / WP.org compliance.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// ── Drop all custom tables ────────────────────────────────────────────────────
$tables = [
    'rto_logs',
    'rto_ratings',
    'rto_complaints',
    'rto_messages',
    'rto_notifications',
    'rto_notification_templates',
    'rto_vendor_payouts',
    'rto_refunds',
    'rto_invoices',
    'rto_payments',
    'rto_documents',
    'rto_lead_meta',
    'rto_assignments',
    'rto_leads',
    'rto_vendors',
    'rto_doc_types',
    'rto_services',
    'rto_rtos',
    'rto_cities',
    'rto_states',
    'rto_api_keys',
    'rto_holidays',
    'rto_migrations',
];

foreach ($tables as $table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
}

// ── Remove custom roles ───────────────────────────────────────────────────────
foreach (['rto_admin', 'rto_staff', 'rto_vendor', 'rto_client'] as $role) {
    remove_role($role);
}

// ── Delete persistent options ─────────────────────────────────────────────────
$options = [
    'rtoflow_company_name',
    'rtoflow_company_gstin',
    'rtoflow_company_address',
    'rtoflow_company_state',
    'rtoflow_admin_user_id',
    'rtoflow_sla_days',
];
foreach ($options as $option) {
    delete_option($option);
}

// ── Delete all rtoflow_* and rtofl_* options (lockout data, etc.) ─────────────
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rtoflow_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rtofl_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rtofl%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_rtofl%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rtoflow%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_rtoflow%'");

// ── Remove scheduled cron events ─────────────────────────────────────────────
wp_clear_scheduled_hook('rtoflow_sla_check');
wp_clear_scheduled_hook('rtoflow_notification_queue');

// ── Remove plugin pages created on activation ─────────────────────────────────
$page_slugs = ['rto-dashboard', 'rto-apply', 'rto-admin', 'rto-vendor'];
foreach ($page_slugs as $slug) {
    $page = get_page_by_path($slug);
    if ($page) {
        wp_delete_post($page->ID, true);
    }
}

// ── Flush rewrite rules ───────────────────────────────────────────────────────
flush_rewrite_rules();
