<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ErrorLogger — Part 12-E (Observability: Structured Logging)
 *
 * Stores runtime errors in the nas_error_log table so admins can diagnose
 * problems from the dashboard without server log access.
 *
 * Also writes to PHP's error_log() as a secondary sink.
 *
 * Severity levels (ascending): debug < info < warning < error < critical
 *
 * Usage:
 *   ErrorLogger::error('Payment capture failed', ['booking_id' => 42, 'gateway' => 'razorpay']);
 *   ErrorLogger::critical('Database write failed — data lost', ['table' => 'nas_bookings']);
 *
 * Auto-pruned after 30 days by 'nas_error_log_prune' cron event (registered in SchemaV4).
 */
class ErrorLogger {

    /**
     * TRACE: Main log entry point.
     *        severity must be one of: debug, info, warning, error, critical.
     *        context is serialized to JSON and stored in the context column.
     *        Precondition: nas_error_log table must exist (SchemaV4::upgrade() ran).
     *        Postcondition: one row in nas_error_log. Also written to PHP error_log.
     *        Edge case: DB insert fails → falls back silently to error_log() only.
     */
    // TRACE: log() — Called internally or via AJAX action.
    //        Steps: inserts DB row.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function log( string $severity, string $message, array $context = [] ): void {
        // Always write to PHP error_log as fallback
        error_log( sprintf( '[NAS][%s] %s %s', strtoupper( $severity ), $message, $context ? json_encode( $context ) : '' ) );

        // Write to DB table for admin visibility
        try {
            $db  = Database::instance();
            $tbl = $db->prefix( 'error_log' );
            $db->insert( $tbl, [
                'severity'    => $severity,
                'message'     => substr( $message, 0, 65535 ),
                'context'     => $context ? wp_json_encode( $context ) : null,
                'user_id'     => get_current_user_id(),
                'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? substr( $_SERVER['REQUEST_URI'], 0, 500 ) : '',
                'created_at'  => current_time( 'mysql' ),
            ] );
        } catch ( \Throwable $e ) {
            // Silently swallowed — logging must never break primary flow
        }

        // For critical severity: send admin alert email
        if ( $severity === 'critical' ) {
            self::alert_admin( $message, $context );
        }
    }

    public static function debug( string $msg, array $ctx = [] ): void   { self::log( 'debug',    $msg, $ctx ); }
    public static function info( string $msg, array $ctx = [] ): void    { self::log( 'info',     $msg, $ctx ); }
    public static function warning( string $msg, array $ctx = [] ): void { self::log( 'warning',  $msg, $ctx ); }
    public static function error( string $msg, array $ctx = [] ): void   { self::log( 'error',    $msg, $ctx ); }
    public static function critical( string $msg, array $ctx = [] ): void { self::log( 'critical', $msg, $ctx ); }

    /**
     * TRACE: Send one-time email alert to admin on critical errors.
     *        Uses admin_alert_email from settings, or WP admin_email as fallback.
     *        Rate-limited: one alert per unique message per hour (transient).
     *        Edge case: wp_mail failure → silently swallowed.
     */
    // TRACE: alert_admin() — Called internally or via AJAX action.
    //        Steps: queries DB → sends email via wp_mail.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function alert_admin( string $message, array $context = [] ): void {
        $throttle_key = 'nas_critical_alert_' . md5( $message );
        if ( get_transient( $throttle_key ) ) return; // already alerted this hour
        set_transient( $throttle_key, 1, HOUR_IN_SECONDS );

        try {
            $db    = Database::instance();
            $email = $db->scalar( "SELECT admin_alert_email FROM `{$db->prefix('settings')}` LIMIT 1" );
            $email = $email ?: get_option( 'admin_email' );
            if ( ! $email ) return;

            $brand = $db->scalar( "SELECT brand_name FROM `{$db->prefix('settings')}` LIMIT 1" ) ?: get_bloginfo('name');
            $body  = "CRITICAL ERROR ALERT — {$brand}\n\n"
                   . "Message: {$message}\n\n"
                   . ( $context ? "Context:\n" . print_r( $context, true ) : '' )
                   . "\nTime: " . gmdate('Y-m-d H:i:s')
                   . "\nURL: " . ( $_SERVER['REQUEST_URI'] ?? 'N/A' );

            wp_mail( $email, "[{$brand}] Critical System Error", $body );
        } catch ( \Throwable $e ) {
            // Silently swallowed
        }
    }

    /**
     * TRACE: Return recent error log rows for admin display.
     *        Filterable by severity. Returns ARRAY_A rows newest-first.
     */
    // TRACE: get_recent() — Called internally or via AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public static function get_recent( string $severity = '', int $limit = 100 ): array {
        try {
            $db = Database::instance();
            if ( $severity ) {
                return $db->select(
                    "SELECT * FROM `{$db->prefix('error_log')}` WHERE severity=%s ORDER BY created_at DESC LIMIT %d",
                    [ $severity, $limit ]
                );
            }
            return $db->select(
                "SELECT * FROM `{$db->prefix('error_log')}` ORDER BY created_at DESC LIMIT %d",
                [ $limit ]
            );
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    /**
     * TRACE: Delete error log rows older than $days days.
     *        Called by 'nas_error_log_prune' daily cron event.
     *        Postcondition: rows older than $days days removed from nas_error_log.
     */
    // TRACE: prune() — Called internally or via AJAX action.
    //        Steps: logs errors via ErrorLogger.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function prune( int $days = 30 ): void {
        try {
            $db = Database::instance();
            $db->query( "DELETE FROM `{$db->prefix('error_log')}` WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)" );
        } catch ( \Throwable $e ) {
            error_log( 'NAS ErrorLogger prune failed: ' . $e->getMessage() );
        }
    }
}
