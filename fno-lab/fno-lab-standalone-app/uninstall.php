<?php
/**
 * Uninstall handler for F&O Lab - Standalone App.
 *
 * TRACE: WordPress core calls this file (never fno-lab.php itself) only
 * when the plugin is deleted via the wp-admin "Delete" action (or
 * `wp plugin uninstall`) - NOT on ordinary deactivation, which is
 * handled separately by the register_deactivation_hook() callback in
 * fno-lab.php (clears the raw-tick-retention cron event only).
 *
 * Preconditions: WordPress defines WP_UNINSTALL_PLUGIN and includes
 * this file directly; it is never reachable by a normal HTTP request
 * (WordPress refuses to run it any other way), so no ABSPATH/nonce/
 * capability check is needed beyond the WP_UNINSTALL_PLUGIN guard
 * below, which is the standard WordPress pattern for this file.
 *
 * REAL FIX (2026-08-30 audit pass - "uninstall/deactivation cleanup"):
 * this plugin previously had NO uninstall.php and NO
 * register_uninstall_hook() at all. Deleting the plugin through
 * wp-admin therefore left every wp_options row this plugin ever wrote
 * - including AES-256-CBC-encrypted TrueData/OpenAI/Kite broker
 * credentials (fno_truedata_credentials, fno_openai_settings,
 * fno_premium_providers) and the encrypted headless-driver/daemon
 * secrets (fno_headless_driver_secret, fno_daemon_ingest_secret) -
 * permanently orphaned in the database with no code path to ever
 * discover, surface, or remove them again. That's a real, if low-
 * severity, "sensitive data lingers forever with no cleanup path" gap:
 * fixed here for every genuinely plugin-owned, non-trade-data option.
 *
 * DELIBERATE, DOCUMENTED SCOPE LIMIT - what this file intentionally
 * does NOT do, and why: it does NOT DROP any of this plugin's DB
 * tables (fno_journal, fno_open_positions, fno_real_money_journal,
 * fno_real_money_accounts, fno_failure_events, fno_manual_positions,
 * fno_participant_hypotheses, fno_rejected_opportunities,
 * fno_factor_values, fno_microstructure, fno_raw_ticks). This is a
 * PERSONAL paper-trading journal and decision-history tool - those
 * tables are the user's own real trade/decision history, exactly the
 * kind of data an accidental "Delete" click (or a hosting migration
 * that deletes-then-reinstalls the plugin) must never silently
 * destroy. Silently dropping months of journaled trade history on an
 * uninstall click would be a strictly worse failure mode than leaving
 * a handful of small, encrypted-at-rest option rows behind. A future,
 * EXPLICIT "Erase all my F&O Lab data" admin action (typed
 * confirmation phrase, exactly like the existing real-money-arming
 * confirmation flow) would be the correct way to offer real table
 * deletion - never an implicit consequence of plugin deletion. See
 * docs/PENDING_REQUIREMENTS.md (2026-08-30 entry) for the open item.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$fno_uninstall_options = [
    // DB schema/migration bookkeeping - meaningless without the plugin.
    'fno_journal_db_version',
    'fno_journal_db_migration_error',
    // Encrypted secrets - genuinely sensitive, no reason to survive an
    // intentional uninstall.
    'fno_headless_driver_secret',
    'fno_headless_driver_user_id',
    'fno_daemon_ingest_secret',
    'fno_truedata_credentials',
    'fno_openai_settings',
    'fno_premium_providers',
    // Cost-control / audit logging and cached model state - plugin-owned,
    // safe and correct to remove.
    'fno_dsm_paid_api_log',
    'fno_strategy_versions',
    'fno_knowledge_base',
    'fno_probability_models',
    'fno_standalone_notice_done',
];
foreach ($fno_uninstall_options as $fno_uninstall_option) {
    delete_option($fno_uninstall_option);
    // Multisite: this plugin has no explicit multisite-activation path,
    // but delete the network-level copy too in case one was ever set
    // directly (e.g. via WP-CLI --network), so a network-activated
    // install doesn't leave a hidden site-option duplicate behind.
    if (function_exists('delete_site_option')) {
        delete_site_option($fno_uninstall_option);
    }
}

// Short-lived caches (market breadth, ban list, event calendar, NSE
// cookie jar / circuit breaker, per-symbol option-chain snapshots) -
// none hold secrets, all already self-expire, but an uninstall is a
// natural, safe point to clear them immediately rather than waiting.
global $wpdb;
if (isset($wpdb) && is_object($wpdb)) {
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_fno\\_%' OR option_name LIKE '\\_transient\\_timeout\\_fno\\_%'"
    );
    if (function_exists('is_multisite') && is_multisite()) {
        $wpdb->query(
            "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\\_site\\_transient\\_fno\\_%' OR meta_key LIKE '\\_site\\_transient\\_timeout\\_fno\\_%'"
        );
    }
}
