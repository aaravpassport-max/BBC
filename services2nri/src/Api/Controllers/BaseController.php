<?php
namespace S2NRI\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use S2NRI\Api\{Request, Response};
use S2NRI\Api\Middleware\Auth;
use S2NRI\Exceptions\{ValidationException, NotFoundException, ForbiddenException};

/**
 * BaseController — shared helpers for all controllers.
 */
abstract class BaseController {

    protected ?array $user;

    public function __construct( ?array $user = null ) {
        $this->user = $user;
    }

    protected function requireStaff(): void  { Auth::requireStaff(  $this->user ); }
    protected function requireManager(): void { Auth::requireManager( $this->user ); }
    protected function requireAdmin(): void   { Auth::requireAdmin(   $this->user ); }

    protected function paginate( Request $req ): array {
        return [
            'page'     => max( 1, (int) $req->query( 'page', 1 ) ),
            'per_page' => min( 100, max( 5, (int) $req->query( 'per_page', 20 ) ) ),
        ];
    }

    protected function offset( int $page, int $per_page ): int {
        return ( $page - 1 ) * $per_page;
    }

    /** Generate booking reference: S2N-YYYYMM-NNNN */
    protected function generateRef(): string {
        global $wpdb;
        // TRACE: generateRef() → atomic counter via s2nri_ref_counter table →
        //        produces NRI-YYYY-NNNN format (e.g. NRI-2026-0001).
        // PRECONDITIONS: s2nri_ref_counter table exists (created in runMigrations).
        // POSTCONDITIONS: returns unique, human-readable NRI-YYYY-NNNN reference.
        // EDGE CASES: concurrent submissions → INSERT ... ON DUPLICATE KEY UPDATE is atomic.
        //             s2nri_ref_counter table missing (old install) → fallback to legacy format.
        $year = (int) date( 'Y' );
        $p    = $wpdb->prefix;

        // Atomic increment: try the counter table first
        $counter_table = $p . 's2nri_ref_counter';
        $table_exists  = $wpdb->get_var( "SHOW TABLES LIKE '{$counter_table}'" );

        if ( $table_exists ) {
            // FIXED: previously did the atomic INSERT...ON DUPLICATE KEY
            // UPDATE, then a SEPARATE SELECT to read back last_seq. The
            // write was atomic; the read was NOT — under concurrent
            // requests, two customers could both read the same last_seq
            // between their own increment and the other's, producing an
            // identical booking_ref. Since booking_ref has a real UNIQUE
            // KEY constraint (Installer.php), the second booking's insert
            // would then fail with a duplicate-key error — a genuine,
            // customer-facing "booking creation failed" caused entirely
            // by this race, not a real system failure.
            //
            // Fixed using LAST_INSERT_ID(expr) inside the UPDATE clause —
            // BUT this table's `year` column is the PRIMARY KEY with no
            // separate AUTO_INCREMENT column (confirmed against the real
            // CREATE TABLE), so LAST_INSERT_ID() is only reliably set on
            // the UPDATE branch (via the explicit LAST_INSERT_ID(expr)
            // call), NOT on a fresh INSERT of a brand-new year's first
            // row. Using $wpdb->rows_affected — MySQL's own documented
            // signal for INSERT...ON DUPLICATE KEY UPDATE: 1 = a new row
            // was inserted, 2 = an existing row was updated — to know
            // deterministically which branch ran, rather than guessing
            // from the resulting value.
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$counter_table} (year, last_seq) VALUES (%d, 1)
                 ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)",
                $year
            ) );
            if ( (int) $wpdb->rows_affected === 1 ) {
                // A brand-new row for this year was just inserted with
                // last_seq=1 as a literal — deterministically correct,
                // no read needed. (InnoDB guarantees at most one
                // concurrent attempt can win this branch for a given
                // year; any other concurrent attempt falls through to
                // the UPDATE branch below instead, never both.)
                $seq = 1;
            } else {
                // rows_affected === 2 (or, defensively, anything else):
                // the UPDATE branch ran, so LAST_INSERT_ID(last_seq + 1)
                // reliably set the connection's insert_id to the new,
                // atomically-incremented value.
                $seq = (int) $wpdb->insert_id;
                if ( $seq < 1 ) {
                    // Should not happen, but never silently produce a
                    // booking_ref with seq=0 — fall back to a direct read
                    // rather than trust an implausible value.
                    $seq = max( 1, (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT last_seq FROM {$counter_table} WHERE year = %d", $year
                    ) ) );
                }
            }
        } else {
            // Fallback: derive sequence from existing booking_refs in s2nri_bookings
            $prefix = 'NRI-' . $year . '-';
            $last   = $wpdb->get_var( $wpdb->prepare(
                "SELECT booking_ref FROM {$p}s2nri_bookings WHERE booking_ref LIKE %s ORDER BY id DESC LIMIT 1",
                $wpdb->esc_like( $prefix ) . '%'
            ) );
            $seq = $last ? ( (int) substr( $last, -4 ) + 1 ) : 1;
        }

        return 'NRI-' . $year . '-' . str_pad( (string) $seq, 4, '0', STR_PAD_LEFT );
    }

    // TRACE: logAudit(bookingId, action, old, new, details) → INSERT s2nri_audit_log.
    //        details is optional array for structured JSON context (payment amounts, etc.).
    //        user_id NULL allowed for system actions (cron jobs).
    protected function logAudit( int $bookingId = null, string $action = '', string $old = '', string $new = '', array $details = [] ): void {
        global $wpdb;
        $result = $wpdb->insert( $wpdb->prefix . 's2nri_audit_log', [
            'booking_id' => $bookingId ?: null,
            'user_id'    => $this->user['wp_id'] ?? null,
            'action'     => $action,
            'old_value'  => $old ?: null,
            'new_value'  => $new ?: null,
            'details'    => $details ? wp_json_encode( $details ) : null,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): this shared helper is
        // called from nearly every controller in the codebase for audit
        // logging. A single missed audit entry is low severity on its
        // own, but this is the single highest-leverage place to catch it
        // — logging on failure here covers every caller at once.
        if ( $result === false ) {
            error_log( "[S2NRI] logAudit() insert failed for action={$action}: " . $wpdb->last_error );
        }
    }
}
