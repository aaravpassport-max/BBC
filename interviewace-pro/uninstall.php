<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
global $wpdb;
$p = $wpdb->prefix;
foreach(['ia_profiles','ia_interviews','ia_turns','ia_reports','ia_saved_answers','ia_subscriptions','ia_payments','ia_usage','ia_refresh_tokens','ia_otp_codes','ia_score_aggregates'] as $t)
    $wpdb->query("DROP TABLE IF EXISTS {$p}{$t}");
delete_option('ia_db_version');
delete_option('ia_app_page_id');
wp_clear_scheduled_hook('ia_generate_report');
wp_clear_scheduled_hook('ia_daily_usage_reset');
wp_clear_scheduled_hook('ia_subscription_sync');
wp_clear_scheduled_hook('ia_cleanup_abandoned');
