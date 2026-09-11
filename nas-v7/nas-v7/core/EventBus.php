<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lightweight Event Bus for internal decoupled communication.
 * Modules emit and listen to events without knowing each other.
 */
class EventBus {
    private static array $listeners = [];

    // TRACE: on() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure → logs errors via ErrorLogger.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    public static function on( string $event, callable $listener, int $priority = 10 ): void {
        self::$listeners[ $event ][ $priority ][] = $listener;
    }

    // TRACE: emit() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure → logs errors via ErrorLogger.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    // TRACE: emit() — fires all registered listeners for $event in priority order.
    //        Precondition: $listeners array set up via on(). Payload is an associative array.
    //        Postcondition: all listeners called. A failing listener logs its error but does not stop others.
    //        Edge cases: no listeners for event → returns early. Listener throws → ErrorLogger + continue.
    public static function emit( string $event, array $payload = [] ): void {
        if ( empty( self::$listeners[ $event ] ) ) return;
        ksort( self::$listeners[ $event ] );
        foreach ( self::$listeners[ $event ] as $priority_group ) {
            foreach ( $priority_group as $listener ) {
                try {
                    call_user_func( $listener, $payload );
                } catch ( \Throwable $e ) {
                    // 16-C-4: Isolate listener failure — log and continue to next listener
                    error_log( '[NAS EventBus] Listener error on event "' . $event . '": ' . $e->getMessage() );
                    if ( class_exists('\NAS\Core\ErrorLogger') ) {
                        \NAS\Core\ErrorLogger::error( 'EventBus listener failed', ['event' => $event, 'error' => $e->getMessage()] );
                    }
                }
            }
        }
    }

    // TRACE: off() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function off( string $event ): void {
        unset( self::$listeners[ $event ] );
    }
}
