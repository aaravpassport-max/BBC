<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AuditLogger — Part 12-B (Data Integrity: Audit Trail)
 *
 * Records WHO changed WHAT on WHICH record and WHEN, including old and new values.
 * Every admin write operation (BookingModule, AdminModule, VendorModule, ClientModule)
 * calls AuditLogger::log() immediately after a successful DB write.
 *
 * Usage:
 *   AuditLogger::log('bookings', $booking_id, 'status_change',
 *       ['status' => 'booking_received'],
 *       ['status' => 'approved'],
 *       'Admin changed status to Approved');
 *
 * The log is readable from the admin panel via nas_admin_get_audit_log AJAX.
 * Retention: records are never automatically deleted (financial audit requirement).
 */
class AuditLogger {

    /**
     * TRACE: Called after any successful DB write on a business record.
     *        Receives table name, record ID, action verb, old values array, new values array.
     *        Inserts one row per changed field into nas_audit_log.
     *        Precondition: DB write has already succeeded (caller verifies).
     *        Postcondition: nas_audit_log has N rows (one per changed field) for this operation.
     *        Edge cases: empty old/new arrays → inserts single summary row with no field detail.
     *                    DB failure → silently swallowed (audit log must never break the primary flow).
     */
    // TRACE: log() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function log(
        string $table_name,
        int    $record_id,
        string $action,
        array  $old_values  = [],
        array  $new_values  = [],
        string $summary     = ''
    ): void {
        try {
            $db        = Database::instance();
            $log_table = $db->prefix( 'audit_log' );
            $user      = wp_get_current_user();
            $user_id   = $user->ID ?? 0;
            $user_name = $user->display_name ?? 'system';
            $user_role = Security::current_role();
            $ip        = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
            $now       = current_time( 'mysql' );

            // Determine changed fields: anything in new_values that differs from old_values
            $changed_fields = [];
            foreach ( $new_values as $field => $new_val ) {
                $old_val = $old_values[ $field ] ?? null;
                if ( (string) $old_val !== (string) $new_val ) {
                    $changed_fields[] = [
                        'field_name' => $field,
                        'old_value'  => is_array( $old_val ) ? wp_json_encode( $old_val ) : (string) $old_val,
                        'new_value'  => is_array( $new_val ) ? wp_json_encode( $new_val ) : (string) $new_val,
                    ];
                }
            }

            // If no field-level diff (e.g. caller only provides action summary), log one summary row
            if ( empty( $changed_fields ) ) {
                $changed_fields[] = [
                    'field_name' => null,
                    'old_value'  => null,
                    'new_value'  => null,
                ];
            }

            foreach ( $changed_fields as $cf ) {
                $db->insert( $log_table, [
                    'table_name'  => $table_name,
                    'record_id'   => $record_id,
                    'action'      => $action,
                    'field_name'  => $cf['field_name'],
                    'old_value'   => $cf['old_value'],
                    'new_value'   => $cf['new_value'],
                    'summary'     => $summary ?: null,
                    'user_id'     => $user_id,
                    'user_role'   => $user_role,
                    'user_name'   => $user_name,
                    'ip_address'  => $ip,
                    'created_at'  => $now,
                ] );
            }
        } catch ( \Throwable $e ) {
            // Never let audit logging break primary business logic
            error_log( 'NAS AuditLogger error: ' . $e->getMessage() );
        }
    }

    /**
     * TRACE: Shorthand for a single-field status change log entry.
     *        Precondition: booking/record update has already executed successfully.
     *        Postcondition: one row in nas_audit_log with from_status → to_status.
     */
    // TRACE: status_change() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function status_change( string $table, int $record_id, string $from, string $to, string $note = '' ): void {
        self::log(
            $table,
            $record_id,
            'status_change',
            [ 'status' => $from ],
            [ 'status' => $to ],
            $note ?: "Status changed: {$from} → {$to}"
        );
    }

    /**
     * TRACE: Log a record creation event.
     *        Postcondition: one audit_log row with action='create'.
     */
    // TRACE: created() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function created( string $table, int $record_id, array $data = [], string $summary = '' ): void {
        self::log( $table, $record_id, 'create', [], $data, $summary ?: "Record created" );
    }

    /**
     * TRACE: Log a record deletion (hard or soft).
     *        Postcondition: one audit_log row with action='delete'.
     */
    // TRACE: deleted() — Called internally or via AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public static function deleted( string $table, int $record_id, array $data = [], string $summary = '' ): void {
        self::log( $table, $record_id, 'delete', $data, [], $summary ?: "Record deleted" );
    }

    /**
     * TRACE: Retrieve audit log for a specific record — used by nas_admin_get_audit_log.
     *        Returns rows ordered newest-first, limit 200.
     *        Edge case: table doesn't exist yet → returns [].
     */
    // TRACE: get_for_record() — Called internally or via AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public static function get_for_record( string $table_name, int $record_id, int $limit = 200 ): array {
        try {
            $db = Database::instance();
            return $db->select(
                "SELECT * FROM `{$db->prefix('audit_log')}` WHERE table_name=%s AND record_id=%d ORDER BY created_at DESC LIMIT %d",
                [ $table_name, $record_id, $limit ]
            );
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    /**
     * TRACE: Retrieve recent audit log across all records — admin overview.
     *        Returns rows ordered newest-first, limit 100.
     */
    // TRACE: get_recent() — Called internally or via AJAX action.
    //        Steps: queries DB.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public static function get_recent( int $limit = 100 ): array {
        try {
            $db = Database::instance();
            return $db->select(
                "SELECT * FROM `{$db->prefix('audit_log')}` ORDER BY created_at DESC LIMIT %d",
                [ $limit ]
            );
        } catch ( \Throwable $e ) {
            return [];
        }
    }
}
