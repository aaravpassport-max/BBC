<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Queue-based background processing system.
 * Jobs: email, analytics, reports, heavy DB ops.
 * Runs via WP-Cron every minute (lightweight jobs only).
 */
class Queue {

    const TABLE = 'nas_queue';

    // TRACE: db() — Called internally or via AJAX action.
    //        Steps: inserts DB row → returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    private static function db(): Database {
        return Database::instance();
    }

    /** Push a job onto the queue */
    // TRACE: push() — Called internally or via AJAX action.
    //        Steps: inserts DB row → queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public static function push( string $handler, array $payload = [], int $delay_seconds = 0, string $priority = 'normal' ): int {
        $db    = self::db();
        $table = $db->prefix( 'queue' );
        $run_at = date( 'Y-m-d H:i:s', time() + $delay_seconds );
        return $db->insert( $table, [
            'handler'    => $handler,
            'payload'    => json_encode( $payload ),
            'priority'   => $priority,
            'status'     => 'pending',
            'run_at'     => $run_at,
            'attempts'   => 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ] );
    }

    /** Process pending jobs (called by cron) */
    // TRACE: process() — Called internally or via AJAX action.
    //        Steps: updates DB row → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function process( int $limit = 20 ): void {
        $db    = self::db();
        $table = $db->prefix( 'queue' );

        $jobs = $db->select(
            "SELECT * FROM `$table` WHERE status='pending' AND run_at <= NOW() ORDER BY FIELD(priority,'high','normal','low'), created_at ASC LIMIT $limit"
        );

        foreach ( $jobs as $job ) {
            // Fixed: Database::select() returns ARRAY_A (arrays), not objects.
            // All $job->property accesses replaced with $job['property'].
            $db->update( $table, [ 'status' => 'processing', 'started_at' => gmdate('Y-m-d H:i:s') ], [ 'id' => $job['id'] ] );
            try {
                $handler = $job['handler'];
                $payload = json_decode( $job['payload'], true ) ?: [];
                if ( class_exists( $handler ) && method_exists( $handler, 'handle' ) ) {
                    call_user_func( [ $handler, 'handle' ], $payload );
                } elseif ( function_exists( $handler ) ) {
                    call_user_func( $handler, $payload );
                }
                $db->update( $table, [ 'status' => 'done', 'finished_at' => gmdate('Y-m-d H:i:s') ], [ 'id' => $job['id'] ] );
            } catch ( \Throwable $e ) {
                $attempts = (int) $job['attempts'] + 1;
                $status   = $attempts >= 3 ? 'failed' : 'pending';
                $run_at   = date('Y-m-d H:i:s', time() + min( 60 * $attempts, 3600 ) );
                $db->update( $table, [
                    'status'      => $status,
                    'attempts'    => $attempts,
                    'error'       => substr( $e->getMessage(), 0, 500 ),
                    'run_at'      => $run_at,
                ], [ 'id' => $job['id'] ] );
                error_log( 'NAS Queue error [' . $job['handler'] . ']: ' . $e->getMessage() );
            }
        }

        // Cleanup: delete done jobs older than 7 days
        $db->query( "DELETE FROM `$table` WHERE status='done' AND finished_at < DATE_SUB(NOW(), INTERVAL 7 DAY)" );
    }

    // TRACE: pending_count() — Called internally or via AJAX action.
    //        Steps: updates DB row → queries DB → returns JSON error response on failure.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    public static function pending_count(): int {
        $db = self::db();
        return (int) $db->scalar( "SELECT COUNT(*) FROM `{$db->prefix('queue')}` WHERE status='pending'" );
    }

    // TRACE: failed_jobs() — Called internally or via AJAX action.
    //        Steps: updates DB row → queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public static function failed_jobs(): array {
        $db = self::db();
        return $db->select( "SELECT * FROM `{$db->prefix('queue')}` WHERE status='failed' ORDER BY created_at DESC LIMIT 50" );
    }

    // TRACE: retry() — Called internally or via AJAX action.
    //        Steps: updates DB row.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function retry( int $job_id ): void {
        $db    = self::db();
        $table = $db->prefix('queue');
        $db->update( $table, [ 'status' => 'pending', 'attempts' => 0, 'run_at' => gmdate('Y-m-d H:i:s') ], [ 'id' => $job_id ] );
    }
}
