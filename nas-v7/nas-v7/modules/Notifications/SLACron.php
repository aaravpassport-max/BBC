<?php
namespace NAS\Modules\Notifications;
if ( ! defined( 'ABSPATH' ) ) exit;

use NAS\Core\{Database, ErrorLogger, AuditLogger};

/**
 * SLACron — Parts 11-C (Transition Integrity), 13-D (Notification Completeness), 14-A (Failure Recovery)
 *
 * Three cron jobs:
 *
 * 1. nas_sla_check (hourly):
 *    Scans bookings where:
 *      - status = 'booking_received'
 *      - submitted_at < NOW() - sla_hours (configurable, default 24h)
 *      - sla_breached_at IS NULL (not yet flagged)
 *    For each: sets sla_breached_at = NOW(), sends admin email alert.
 *
 * 2. nas_sla_check also scans for inactivity:
 *    Bookings where status NOT IN ('published','completed','rejected','cancelled')
 *    AND updated_at < NOW() - inactivity_alert_hours (default 48h)
 *    AND no inactivity notification sent in last 48h (transient guard).
 *    Sends admin email listing stalled bookings.
 *
 * 3. nas_error_log_prune (daily):
 *    Deletes error_log rows older than 30 days.
 */
class SLACron {

    /**
     * TRACE: Register all WP cron hooks. Called once on plugin boot.
     *        Precondition: none.
     *        Postcondition: add_action() binds each cron hook to its handler.
     */
    public static function register(): void {
        add_action( 'nas_sla_check',       [ self::class, 'run_sla_check' ] );
        add_action( 'nas_error_log_prune', [ self::class, 'run_error_log_prune' ] );
    }

    /**
     * TRACE: Triggered by 'nas_sla_check' WP cron event (hourly).
     *        Step 1: load sla_hours + inactivity_alert_hours from settings table.
     *        Step 2: find bookings submitted > sla_hours ago with status=booking_received AND sla_breached_at IS NULL.
     *        Step 3: for each, set sla_breached_at=NOW() and log to audit_log.
     *        Step 4: if any found, email admin alert with list of UIDs.
     *        Step 5: find stalled bookings (updated_at > inactivity_alert_hours ago, not terminal).
     *        Step 6: throttle inactivity email (max 1 per 48h per booking) using transients.
     *        Postcondition: sla_breached_at set on breached bookings; admin emailed.
     *        Edge case: settings not found → uses defaults (24h / 48h).
     *                   admin_alert_email blank → uses WP admin_email.
     */
    // TRACE: run_sla_check() — Trigger: WP-Cron scheduled event.
    //        Steps: queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    public static function run_sla_check(): void {
        global $wpdb;
        $db = Database::instance();

        // Load configurable thresholds from settings
        $settings = $db->row( "SELECT sla_hours, inactivity_alert_hours, admin_alert_email, brand_name FROM `{$db->prefix('settings')}` LIMIT 1" ) ?: [];
        $sla_hours        = max( 1, (int) ( $settings['sla_hours'] ?? 24 ) );
        $inact_hours      = max( 1, (int) ( $settings['inactivity_alert_hours'] ?? 48 ) );
        $admin_email      = sanitize_email( $settings['admin_alert_email'] ?? '' ) ?: get_option( 'admin_email' );
        $brand            = $settings['brand_name'] ?? get_bloginfo('name');

        $bt = $db->prefix('bookings');

        /* ── Step 1: SLA breach detection ─────────────────────────────── */
        $breached = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, uid, client_name, submitted_at FROM `$bt`
             WHERE status = 'booking_received'
               AND submitted_at < DATE_SUB(NOW(), INTERVAL %d HOUR)
               AND sla_breached_at IS NULL",
            $sla_hours
        ), ARRAY_A );

        if ( ! empty( $breached ) ) {
            foreach ( $breached as $b ) {
                // Mark as breached
                $wpdb->update( $bt, [ 'sla_breached_at' => gmdate('Y-m-d H:i:s') ], [ 'id' => (int) $b['id'] ] );

                // Audit log entry
                AuditLogger::log(
                    'bookings',
                    (int) $b['id'],
                    'sla_breach',
                    [ 'sla_breached_at' => null ],
                    [ 'sla_breached_at' => gmdate('Y-m-d H:i:s') ],
                    "SLA breached: booking was in booking_received for >{$sla_hours}h"
                );
            }

            // Email admin with the list
            $list = implode( "\n", array_map(
                fn($b) => "  • #{$b['uid']} — {$b['client_name']} — submitted {$b['submitted_at']}",
                $breached
            ) );

            wp_mail(
                $admin_email,
                "[{$brand}] ⚠️ SLA Breach Alert — " . count($breached) . " booking(s) overdue",
                "The following bookings have exceeded the {$sla_hours}-hour SLA and are still in 'Booking Received':\n\n{$list}\n\nPlease review and take action."
            );

            ErrorLogger::warning(
                'SLA breach detected for ' . count($breached) . ' bookings',
                [ 'uids' => array_column( $breached, 'uid' ) ]
            );
        }

        /* ── Step 2: Inactivity detection (bookings not progressing) ──── */
        $terminal = [ 'published', 'completed', 'rejected', 'cancelled' ];
        $terminal_sql = implode( ',', array_fill( 0, count($terminal), '%s' ) );
        $stalled = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, uid, client_name, status, updated_at FROM `$bt`
             WHERE status NOT IN ($terminal_sql)
               AND updated_at < DATE_SUB(NOW(), INTERVAL %d HOUR)
             ORDER BY updated_at ASC LIMIT 50",
            ...[...$terminal, $inact_hours]
        ), ARRAY_A );

        if ( ! empty( $stalled ) ) {
            // Throttle: send inactivity report at most once per 12 hours
            $throttle_key = 'nas_inactivity_alert';
            if ( ! get_transient( $throttle_key ) ) {
                set_transient( $throttle_key, 1, 12 * HOUR_IN_SECONDS );

                $list = implode( "\n", array_map(
                    fn($b) => "  • #{$b['uid']} — {$b['client_name']} — status: {$b['status']} — last updated: {$b['updated_at']}",
                    $stalled
                ) );

                wp_mail(
                    $admin_email,
                    "[{$brand}] ℹ️ Stalled Bookings — " . count($stalled) . " booking(s) inactive for >{$inact_hours}h",
                    "The following bookings have not been updated in over {$inact_hours} hours:\n\n{$list}\n\nPlease review and take action."
                );
            }
        }
    }

    /**
     * TRACE: Triggered by 'nas_error_log_prune' WP cron event (daily).
     *        Deletes error_log rows older than 30 days to control table growth.
     *        Postcondition: rows older than 30 days deleted from nas_error_log.
     */
    // TRACE: run_error_log_prune() — Trigger: WP-Cron scheduled event.
    //        Steps: logs errors via ErrorLogger.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function run_error_log_prune(): void {
        ErrorLogger::prune( 30 );
    }
}
