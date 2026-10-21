<?php
/**
 * Plugin Name: LeadCRM - Automated Lead Management System
 * Description: Enterprise CRM — AI intelligence, multi-channel lead capture, team inbox, client portal, payment links, outbound webhooks, visual pipeline analytics.
 * Version:     5.1.4
 * Author:      LeadCRM
 * License:     GPL2
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LCRM_VERSION', '5.1.4' );
define( 'LCRM_PATH',    plugin_dir_path( __FILE__ ) );
define( 'LCRM_URL',     plugin_dir_url( __FILE__ ) );

// ── Core modules (order matters — helpers before all consumers)
require_once LCRM_PATH . 'includes/install.php';
require_once LCRM_PATH . 'includes/roles.php';
require_once LCRM_PATH . 'includes/helpers.php';
require_once LCRM_PATH . 'includes/automation.php';
require_once LCRM_PATH . 'includes/messaging.php';
require_once LCRM_PATH . 'includes/ajax.php';
require_once LCRM_PATH . 'includes/cron.php';
require_once LCRM_PATH . 'includes/frontend.php';
require_once LCRM_PATH . 'admin/menus.php';
require_once LCRM_PATH . 'includes/wa-capture.php';
require_once LCRM_PATH . 'includes/wa-ajax.php';
require_once LCRM_PATH . 'includes/wa-admin.php';

// ── v4.0 modules — AI + multi-channel capture
require_once LCRM_PATH . 'includes/ai-engine.php';
require_once LCRM_PATH . 'includes/email-capture.php';
require_once LCRM_PATH . 'includes/social-capture.php';
require_once LCRM_PATH . 'includes/form-capture.php';
require_once LCRM_PATH . 'includes/pdf-intelligence.php';
require_once LCRM_PATH . 'includes/outbound-wa.php';

// ── v5.0 premium/enterprise modules
require_once LCRM_PATH . 'includes/analytics.php';
require_once LCRM_PATH . 'includes/team-inbox.php';
require_once LCRM_PATH . 'includes/client-portal.php';
require_once LCRM_PATH . 'includes/payment-links.php';
require_once LCRM_PATH . 'includes/webhooks.php';
require_once LCRM_PATH . 'includes/ai-proactive.php';
require_once LCRM_PATH . 'includes/wa-templates.php';
require_once LCRM_PATH . 'includes/onboarding.php';

register_activation_hook( __FILE__, 'lcrm_activate' );
register_deactivation_hook( __FILE__, 'lcrm_deactivate' );

function lcrm_activate() {
    lcrm_install_tables();
    update_option('lcrm_cols_v34', 1); // columns exist on fresh install
    lcrm_wa_install_tables();
    lcrm_email_install_tables();
    lcrm_social_install_tables();
    lcrm_pdf_install_tables();
    lcrm_analytics_install_tables();
    lcrm_team_install_tables();
    lcrm_portal_install_tables();
    lcrm_payments_install_tables();
    lcrm_webhooks_install_tables();
    lcrm_wa_templates_install_tables();
    lcrm_team_seed_sla_rules();
    lcrm_wa_templates_seed_defaults();
    lcrm_spa_setup_page();
    lcrm_register_roles();
    lcrm_seed_defaults();
    lcrm_sync_remarks();
    lcrm_schedule_cron();
    flush_rewrite_rules();
}

// ── Runtime migrations — run once each, safe for existing installs ──────────

// Ensure SPA /crm page exists on every admin load (cheap option check)
add_action('admin_init', 'lcrm_runtime_ensure_spa_page');
function lcrm_runtime_ensure_spa_page() {
    if ( ! current_user_can('manage_options') ) return;
    $page_id = (int) get_option('lcrm_spa_page_id', 0);
    if ( ! $page_id || get_post_status($page_id) !== 'publish' ) {
        if ( function_exists('lcrm_spa_setup_page') ) lcrm_spa_setup_page();
    }
}

// Seed work_types / lead_sources for older installs
add_action('admin_init', 'lcrm_runtime_seed_check');
function lcrm_runtime_seed_check() {
    if ( ! current_user_can('manage_options') ) return;
    if ( get_option('lcrm_seeded_v2', 0) ) return;
    global $wpdb;
    $has_wt = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_work_types");
    if ( ! $has_wt ) {
        foreach (['NRI Documentation','Visa Services','OCI/PIO Services','Legal Services','Property Services','Tax & Finance','Education Docs','Marriage Certificate','Birth Certificate','Police Clearance'] as $i=>$w)
            $wpdb->insert("{$wpdb->prefix}lcrm_work_types",['name'=>$w,'sort_order'=>$i,'is_active'=>1]);
    }
    $has_ls = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_lead_sources");
    if ( ! $has_ls ) {
        foreach (['Website','WhatsApp','Google Ads','Facebook Ads','Instagram','Referral','Walk-in','Phone Inquiry','Email','LinkedIn'] as $i=>$s)
            $wpdb->insert("{$wpdb->prefix}lcrm_lead_sources",['name'=>$s,'sort_order'=>$i,'is_active'=>1]);
    }
    update_option('lcrm_seeded_v2', 1);
}

// v3.4 column migration (assigned_to, is_access_revoked, conversations, assignments)
add_action('admin_init', 'lcrm_runtime_column_migrate');
function lcrm_runtime_column_migrate() {
    if ( ! current_user_can('manage_options') ) return;
    if ( get_option('lcrm_cols_v34', 0) ) return;
    global $wpdb;
    $cols = (array) $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}lcrm_leads", 0);
    if ( empty($cols) ) return;
    if ( ! in_array('assigned_to', $cols, true) )
        $wpdb->query("ALTER TABLE {$wpdb->prefix}lcrm_leads ADD COLUMN assigned_to BIGINT UNSIGNED DEFAULT NULL");
    if ( ! in_array('is_access_revoked', $cols, true) )
        $wpdb->query("ALTER TABLE {$wpdb->prefix}lcrm_leads ADD COLUMN is_access_revoked TINYINT(1) NOT NULL DEFAULT 0");
    $ct = $wpdb->prefix . 'lcrm_conversations';
    if ( $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ct)) !== $ct ) {
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE IF NOT EXISTS {$ct} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id      BIGINT UNSIGNED NOT NULL,
            user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_name    VARCHAR(200)    NOT NULL DEFAULT '',
            user_role    VARCHAR(50)     NOT NULL DEFAULT 'Staff',
            direction    ENUM('staff','client','system') NOT NULL DEFAULT 'staff',
            message      TEXT            NOT NULL,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY lead_id (lead_id)
        ) $c;");
    }
    $at = $wpdb->prefix . 'lcrm_lead_assignments';
    if ( $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $at)) !== $at ) {
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE IF NOT EXISTS {$at} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id       BIGINT UNSIGNED NOT NULL,
            action        ENUM('assigned','reassigned','revoked','taken','transferred') NOT NULL DEFAULT 'assigned',
            from_user_id  BIGINT UNSIGNED DEFAULT NULL,
            to_user_id    BIGINT UNSIGNED DEFAULT NULL,
            done_by       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            note          VARCHAR(255)    NOT NULL DEFAULT '',
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY lead_id (lead_id)
        ) $c;");
    }
    $has_remarks = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_remarks");
    if ( ! $has_remarks && function_exists('lcrm_seed_full_remarks') ) lcrm_seed_full_remarks();
    update_option('lcrm_cols_v34', 1);
}

// v3.5/3.9 migration — tags tables
add_action('admin_init', 'lcrm_runtime_migrate_v35');
function lcrm_runtime_migrate_v35() {
    if ( ! current_user_can('manage_options') ) return;
    if ( get_option('lcrm_cols_v35', 0) ) return;
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_tags (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(80)     NOT NULL,
        color      VARCHAR(7)      NOT NULL DEFAULT '#6366f1',
        created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $c;");
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_lead_tags (
        lead_id BIGINT UNSIGNED NOT NULL,
        tag_id  BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (lead_id, tag_id),
        KEY tag_id (tag_id)
    ) $c;");
    $has_remarks = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_remarks");
    if ( ! $has_remarks && function_exists('lcrm_seed_full_remarks') ) lcrm_seed_full_remarks();
    update_option('lcrm_cols_v35', 1);
}

// WA tables migration
add_action('admin_init', 'lcrm_runtime_migrate_wa');
function lcrm_runtime_migrate_wa() {
    if ( ! current_user_can('manage_options') ) return;
    if ( get_option('lcrm_wa_db_v1', 0) ) return;
    lcrm_wa_install_tables();
    update_option('lcrm_wa_db_v1', 1);
}

// v4.0 migration — email/social/pdf tables + new lead sources + email cron
add_action('admin_init', 'lcrm_runtime_migrate_v40');
function lcrm_runtime_migrate_v40() {
    if ( ! current_user_can('manage_options') ) return;
    if ( get_option('lcrm_v40_migrated', 0) ) return;
    lcrm_email_install_tables();
    lcrm_social_install_tables();
    lcrm_pdf_install_tables();
    global $wpdb;
    $existing_sources = $wpdb->get_col("SELECT name FROM {$wpdb->prefix}lcrm_lead_sources");
    foreach (['Instagram','Facebook Messenger','Website Chat','API','WooCommerce'] as $src) {
        if ( ! in_array($src, $existing_sources, true) ) {
            $max = (int)$wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}lcrm_lead_sources");
            $wpdb->insert("{$wpdb->prefix}lcrm_lead_sources", ['name'=>$src,'sort_order'=>$max+1,'is_active'=>1]);
        }
    }
    lcrm_email_schedule_cron();
    update_option('lcrm_v40_migrated', 1);
}

// v5.0 migration — analytics/team/portal/payments/webhooks/wa-templates
add_action('admin_init', 'lcrm_runtime_migrate_v50');
function lcrm_runtime_migrate_v50() {
    if ( ! current_user_can('manage_options') ) return;
    if ( get_option('lcrm_v50_migrated', 0) ) return;
    lcrm_analytics_install_tables();
    lcrm_team_install_tables();
    lcrm_portal_install_tables();
    lcrm_payments_install_tables();
    lcrm_webhooks_install_tables();
    lcrm_wa_templates_install_tables();
    lcrm_team_seed_sla_rules();
    lcrm_wa_templates_seed_defaults();
    lcrm_spa_setup_page();
    flush_rewrite_rules();
    update_option('lcrm_v50_migrated', 1);
}

function lcrm_deactivate() {
    wp_clear_scheduled_hook( 'lcrm_hourly_cron' );
    wp_clear_scheduled_hook( 'lcrm_daily_cron' );
    wp_clear_scheduled_hook( 'lcrm_email_poll_cron' );
    flush_rewrite_rules();
}

add_action( 'admin_enqueue_scripts', 'lcrm_admin_assets' );
function lcrm_admin_assets( $hook ) {
    if ( strpos( $hook, 'leadcrm' ) === false ) return;
    wp_enqueue_style(  'lcrm-admin', LCRM_URL . 'assets/css/admin.css', [], LCRM_VERSION );
    wp_enqueue_script( 'sortablejs', 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js', [], null, true );
    wp_enqueue_script( 'lcrm-admin', LCRM_URL . 'assets/js/admin.js',
        ['jquery','jquery-ui-sortable','jquery-ui-draggable','jquery-ui-droppable','sortablejs'], LCRM_VERSION, true );
    $lcrm_stages_for_js  = function_exists('lcrm_get_stages')  ? array_map(function($s){ return ['id'=>(int)$s->id,'name'=>$s->name,'color'=>$s->color]; }, lcrm_get_stages())  : [];
    $lcrm_vendors_for_js = function_exists('lcrm_get_vendors') ? array_map(function($v){ return ['id'=>(int)$v->id,'name'=>$v->name]; }, lcrm_get_vendors()) : [];
    wp_enqueue_style(  'lcrm-wa-admin', LCRM_URL . 'assets/css/wa-admin.css', ['lcrm-admin'], LCRM_VERSION );
    wp_enqueue_script( 'lcrm-wa-admin', LCRM_URL . 'assets/js/wa-admin.js', ['lcrm-admin'], LCRM_VERSION, true );
    wp_localize_script( 'lcrm-admin', 'LCRM', [
        'ajax_url'         => admin_url( 'admin-ajax.php' ),
        'nonce'            => wp_create_nonce( 'lcrm_nonce' ),
        'user_id'          => get_current_user_id(),
        'is_admin'         => current_user_can( 'manage_options' ) ? 1 : 0,
        'can_conf_finance' => lcrm_can_see_confidential_finance() ? 1 : 0,
        'list_fields'  => function_exists('lcrm_get_list_fields')   ? array_map(function($f){ return ['key'=>$f->field_key,'label'=>$f->field_label,'in_list'=>1]; }, lcrm_get_list_fields())   : [],
        'all_fields'   => function_exists('lcrm_get_active_fields') ? array_map(function($f){ return ['key'=>$f->field_key,'label'=>$f->field_label,'in_list'=>(int)$f->show_in_list]; }, lcrm_get_active_fields()) : [],
        'stages'       => $lcrm_stages_for_js,
        'vendors'      => $lcrm_vendors_for_js,
        'open_add'     => (isset($_GET['open_add']) && $_GET['open_add'] == '1') ? 1 : 0,
    ]);
}

add_action('init', function () {
    if ( get_option('lcrm_work_status_v1', 0) ) return;
    global $wpdb;
    $t = $wpdb->prefix . 'lcrm_leads';
    if ( $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) !== $t ) return;
    $cols = (array) $wpdb->get_col("SHOW COLUMNS FROM {$t}", 0);
    if ( ! in_array('work_status', $cols, true) )
        $wpdb->query("ALTER TABLE {$t} ADD COLUMN work_status VARCHAR(30) NOT NULL DEFAULT 'Inquiry'");
    update_option('lcrm_work_status_v1', 1);
}, 5);
