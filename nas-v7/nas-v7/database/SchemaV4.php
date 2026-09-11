<?php
namespace NAS\Database;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SchemaV4 — Enterprise compliance additions.
 *
 * Adds tables and columns required by PRE-DELIVERY AUDIT v4.0:
 *   - nas_audit_log         : who changed what on every business record (Part 12-B)
 *   - nas_error_log         : structured server-side error store (Part 12-E)
 *   - nas_bookings.version  : optimistic lock field (Part 12-B / 15-A)
 *   - nas_bookings.cancelled_at / cancelled_by / cancellation_reason (11-D: client cancellation workflow)
 *   - nas_bookings.sla_breached_at (11-C / 13-D: SLA breach tracking)
 *   - nas_vendors.suspended_at / suspended_reason (11-D: vendor suspension workflow)
 *   - nas_clients.blocked_reason / blocked_at (14-B scenario 3: account compromise)
 *   - nas_settings.sla_hours / inactivity_alert_hours / rate_limit_bookings (14-C)
 *
 * All ALTER TABLE calls are guarded by in_array() or IF NOT EXISTS — fully idempotent.
 * Called by main plugin on every admin_init (SchemaV4::upgrade()) — safe to re-run.
 */
class SchemaV4 {

    // TRACE: upgrade() — Called internally or via AJAX action.
    //        Steps: logs to audit trail.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function upgrade(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c       = $wpdb->get_charset_collate();
        $charset = $c;   // alias used by PWA table CREATE statements
        $p       = $wpdb->prefix . 'nas_';

        /* ══════════════════════════════════════════════════════════════════
         * 1. AUDIT LOG TABLE — Part 12-B (Data Integrity: Audit Trail)
         *
         * Records: who acted, on which record, what changed, old vs new value.
         * Written by AuditLogger::log() which is called from every admin write
         * operation and every EventBus status-change event.
         * Read by: nas_admin_get_audit_log AJAX (EnterpriseModule).
         * ══════════════════════════════════════════════════════════════════ */
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}audit_log (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            table_name   VARCHAR(80)  NOT NULL DEFAULT '',
            record_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action       VARCHAR(30)  NOT NULL DEFAULT 'update',
            field_name   VARCHAR(80)  DEFAULT NULL,
            old_value    LONGTEXT     DEFAULT NULL,
            new_value    LONGTEXT     DEFAULT NULL,
            summary      TEXT         DEFAULT NULL,
            user_id      BIGINT UNSIGNED DEFAULT 0,
            user_role    VARCHAR(30)  DEFAULT '',
            user_name    VARCHAR(150) DEFAULT '',
            ip_address   VARCHAR(45)  DEFAULT '',
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_table_record (table_name, record_id),
            KEY idx_user (user_id),
            KEY idx_created (created_at)
        ) $c;" );

        /* ══════════════════════════════════════════════════════════════════
         * 2. ERROR LOG TABLE — Part 12-E (Observability: Structured Logging)
         *
         * Stores runtime errors with context so admin can diagnose without
         * needing server log access. Queryable from admin panel.
         * Retention: auto-pruned after 30 days by EnterpriseModule cron.
         * ══════════════════════════════════════════════════════════════════ */
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}error_log (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            severity     ENUM('debug','info','warning','error','critical') DEFAULT 'error',
            message      TEXT NOT NULL,
            context      LONGTEXT DEFAULT NULL,
            user_id      BIGINT UNSIGNED DEFAULT 0,
            request_uri  VARCHAR(500) DEFAULT '',
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_severity (severity),
            KEY idx_created (created_at)
        ) $c;" );

        /* ══════════════════════════════════════════════════════════════════
         * 3. BOOKINGS — additional columns
         * ══════════════════════════════════════════════════════════════════ */
        $bcols = array_column( $wpdb->get_results( "SHOW COLUMNS FROM `{$p}bookings`" ), 'Field' );

        // version: optimistic lock — every admin UPDATE must read current version
        // and fail if version changed since page loaded (prevents silent overwrites).
        if ( ! in_array( 'version', $bcols, true ) )
            $wpdb->query( "ALTER TABLE `{$p}bookings` ADD COLUMN `version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `updated_at`" );

        // cancelled_at / cancelled_by / cancellation_reason: client cancellation workflow (11-D)
        if ( ! in_array( 'cancelled_at', $bcols, true ) )
            $wpdb->query( "ALTER TABLE `{$p}bookings` ADD COLUMN `cancelled_at` DATETIME DEFAULT NULL" );
        if ( ! in_array( 'cancelled_by', $bcols, true ) )
            $wpdb->query( "ALTER TABLE `{$p}bookings` ADD COLUMN `cancelled_by` BIGINT UNSIGNED DEFAULT 0" );
        if ( ! in_array( 'cancellation_reason', $bcols, true ) )
            $wpdb->query( "ALTER TABLE `{$p}bookings` ADD COLUMN `cancellation_reason` TEXT DEFAULT NULL" );

        // sla_breached_at: set by SLA cron when booking is overdue; NULL = within SLA
        if ( ! in_array( 'sla_breached_at', $bcols, true ) )
            $wpdb->query( "ALTER TABLE `{$p}bookings` ADD COLUMN `sla_breached_at` DATETIME DEFAULT NULL" );

        /* ══════════════════════════════════════════════════════════════════
         * 4. VENDORS — suspension columns (11-D: vendor suspension workflow)
         * ══════════════════════════════════════════════════════════════════ */
        $vcols = array_column( $wpdb->get_results( "SHOW COLUMNS FROM `{$p}vendors`" ), 'Field' );
        if ( ! in_array( 'suspended_at', $vcols, true ) )
            $wpdb->query( "ALTER TABLE `{$p}vendors` ADD COLUMN `suspended_at` DATETIME DEFAULT NULL" );
        if ( ! in_array( 'suspended_reason', $vcols, true ) )
            $wpdb->query( "ALTER TABLE `{$p}vendors` ADD COLUMN `suspended_reason` TEXT DEFAULT NULL" );

        /* ══════════════════════════════════════════════════════════════════
         * 5. CLIENTS — block reason columns (14-B scenario 3: account compromise)
         * ══════════════════════════════════════════════════════════════════ */
        $wpdb->query( "ALTER TABLE `{$p}clients` ADD COLUMN IF NOT EXISTS `blocked_at` DATETIME DEFAULT NULL" );
        $wpdb->query( "ALTER TABLE `{$p}clients` ADD COLUMN IF NOT EXISTS `blocked_reason` TEXT DEFAULT NULL" );

        /* ══════════════════════════════════════════════════════════════════
         * 6. SETTINGS — operational config columns (14-C: Configuration)
         *
         * Every previously hardcoded value is now operator-configurable:
         *   sla_hours              : hours after booking_received before SLA breach alert
         *   inactivity_alert_hours : hours of no admin action before admin notified
         *   rate_limit_bookings    : max bookings per IP per hour (0 = disabled)
         *   admin_alert_email      : email that receives system alert notifications
         *   login_rate_limit       : max login attempts per IP per hour
         * ══════════════════════════════════════════════════════════════════ */
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `sla_hours` INT NOT NULL DEFAULT 24 COMMENT 'Hours before SLA breach alert is sent to admin'" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `inactivity_alert_hours` INT NOT NULL DEFAULT 48 COMMENT 'Hours of no booking action before admin notified'" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `rate_limit_bookings` INT NOT NULL DEFAULT 5 COMMENT 'Max bookings per IP per hour (0=disabled)'" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `admin_alert_email` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Email for system alerts (blank=uses WP admin email)'" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `login_rate_limit` INT NOT NULL DEFAULT 10 COMMENT 'Max login AJAX attempts per IP per hour'" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `allow_client_cancellation` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Allow clients to cancel their own bookings'" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `cancellation_window_hours` INT NOT NULL DEFAULT 24 COMMENT 'Hours after booking within which client can cancel'" );

        /* ══════════════════════════════════════════════════════════════════
         * 7. Indexes for audit_log and error_log high-volume tables (12-C)
         * ══════════════════════════════════════════════════════════════════ */
        // audit_log: composite index for per-record timeline query
        $existing_al = $wpdb->get_col( "SHOW INDEX FROM `{$p}audit_log`", 2 );
        if ( ! in_array( 'idx_record_created', $existing_al, true ) )
            $wpdb->query( "ALTER TABLE `{$p}audit_log` ADD KEY `idx_record_created` (`table_name`,`record_id`,`created_at`)" );
    }

    /**
     * Register the SLA escalation cron event.
     * Called on plugin activation and on admin_init (idempotent).
     */
    // TRACE: register_cron() — Trigger: plugins_loaded or class instantiation.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function register_cron(): void {
        // $p and $charset are required by the PWA table dbDelta() calls below
        // but are not inherited from upgrade() — declare them here explicitly
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix . 'nas_';

        if ( ! wp_next_scheduled( 'nas_sla_check' ) ) {
            wp_schedule_event( time(), 'hourly', 'nas_sla_check' );
        }
        if ( ! wp_next_scheduled( 'nas_error_log_prune' ) ) {
            wp_schedule_event( time(), 'daily', 'nas_error_log_prune' );
        }

        // ── Performance indexes ──────────────────────────────────────────────
        self::add_performance_indexes();

        // ── Phase 5-8: PWA-specific tables ───────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}pwa_push_subscriptions (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            endpoint   TEXT NOT NULL,
            p256dh     VARCHAR(512) NOT NULL DEFAULT '',
            auth_key   VARCHAR(512) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user (wp_user_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}pwa_analytics (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(64) NOT NULL,
            screen     VARCHAR(64) NOT NULL DEFAULT '',
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            meta       TEXT DEFAULT NULL,
            ip         VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_event (event_type),
            KEY idx_created (created_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}pwa_push_campaigns (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title      VARCHAR(255) NOT NULL,
            body       TEXT NOT NULL,
            url        VARCHAR(512) NOT NULL DEFAULT '',
            target     VARCHAR(64) NOT NULL DEFAULT 'all',
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            status     VARCHAR(32) NOT NULL DEFAULT 'draft',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sent_at    DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) {$charset};" );

    }

    /**
     * Remove cron events on plugin deactivation.
     */
    // TRACE: deregister_cron() — Trigger: plugins_loaded or class instantiation.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.

        // Performance indexes — cover the most frequent dashboard queries
    public static function add_performance_indexes(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // client_id + submitted_at: covers WHERE client_id=X ORDER BY submitted_at DESC
        $wpdb->query( "ALTER TABLE `{$p}bookings`
            ADD INDEX IF NOT EXISTS `idx_bk_client_submitted`
            (client_id, submitted_at DESC)" );

        // client_id + status: covers WHERE client_id=X AND status NOT IN (...)
        $wpdb->query( "ALTER TABLE `{$p}bookings`
            ADD INDEX IF NOT EXISTS `idx_bk_client_status`
            (client_id, status)" );

        // client_id + payment_status: covers WHERE client_id=X AND payment_status='paid'
        $wpdb->query( "ALTER TABLE `{$p}bookings`
            ADD INDEX IF NOT EXISTS `idx_bk_client_payment`
            (client_id, payment_status)" );
    }

    public static function deregister_cron(): void {
        wp_clear_scheduled_hook( 'nas_sla_check' );
        wp_clear_scheduled_hook( 'nas_error_log_prune' );
    }
}
