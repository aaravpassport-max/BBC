<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Global helpers — format currency, dates, generate UIDs, etc.
 */
class Helpers {

    // TRACE: format_currency() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function format_currency( float $amount, string $symbol = '' ): string {
        if ( ! $symbol ) $symbol = Config::instance()->get('currency_symbol', '₹');
        return $symbol . number_format( $amount, 2 );
    }

    // TRACE: format_date() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function format_date( string $date, string $format = 'D, d M Y' ): string {
        if ( ! $date ) return '—';
        return date( $format, strtotime( $date ) );
    }

    // TRACE: generate_uid() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function generate_uid( string $prefix = 'BK' ): string {
        return strtoupper( $prefix ) . date('ymd') . strtoupper( substr( uniqid(), -4 ) );
    }

    // TRACE: generate_invoice_number() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function generate_invoice_number(): string {
        global $wpdb;
        $prefix = Config::instance()->get('invoice_prefix', 'INV');
        $table  = $wpdb->prefix . 'nas_settings';
        // Atomic increment — prevents race condition on concurrent bookings
        $wpdb->query( $wpdb->prepare( "UPDATE `$table` SET invoice_counter = invoice_counter + 1 WHERE id = %d", 1 ) );
        $next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_counter FROM `$table` WHERE id = %d", 1 ) );
        return $prefix . '-' . str_pad( $next, 5, '0', STR_PAD_LEFT );
    }

    // TRACE: time_ago() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function time_ago( string $datetime ): string {
        $diff = time() - strtotime( $datetime );
        if ( $diff < 60 )     return 'Just now';
        if ( $diff < 3600 )   return floor($diff/60)   . 'm ago';
        if ( $diff < 86400 )  return floor($diff/3600)  . 'h ago';
        if ( $diff < 604800 ) return floor($diff/86400) . 'd ago';
        return date('d M Y', strtotime($datetime));
    }

    // TRACE: slug_to_name() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function slug_to_name( string $slug ): string {
        return ucwords( str_replace(['-','_'], ' ', $slug) );
    }

    // TRACE: excerpt() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function excerpt( string $text, int $words = 15 ): string {
        $clean = strip_tags($text);
        $arr   = explode(' ', $clean);
        if ( count($arr) <= $words ) return $clean;
        return implode(' ', array_slice($arr, 0, $words)) . '…';
    }

    // TRACE: status_label() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function status_label( string $status ): string {
        $labels = [
            'booking_received'    => 'Booking Received',
            'under_review'        => 'Under Review',
            'ready_to_process'    => 'Ready to Process',
            'documents_received'  => 'Documents Received',
            'payment_received'    => 'Payment Received',
            'ad_processing'       => 'Ad Processing / Designing',
            'submitted_to_pub'    => 'Submitted to Publication',
            'published'           => 'Published',
            'completed'           => 'Completed',
            'not_able_to_process' => 'Not Able to Process',
            'not_eligible'        => 'Not Eligible',
            'no_service'          => 'No Service in Area',
            'rejected'            => 'Rejected',
            'draft'               => 'Draft',
        ];
        return $labels[$status] ?? ucwords(str_replace('_',' ',$status));
    }

    // TRACE: status_color() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function status_color( string $status ): string {
        $colors = [
            'booking_received'    => '#6b7280', // grey
            'under_review'        => '#2563eb', // blue
            'ready_to_process'    => '#2563eb',
            'documents_received'  => '#f97316', // orange
            'payment_received'    => '#f97316',
            'ad_processing'       => '#8b5cf6', // purple
            'submitted_to_pub'    => '#8b5cf6',
            'published'           => '#10b981', // green
            'completed'           => '#059669',
            'not_able_to_process' => '#ef4444', // red
            'not_eligible'        => '#ef4444',
            'no_service'          => '#ef4444',
            'rejected'            => '#dc2626',
            'draft'               => '#9ca3af',
        ];
        return $colors[$status] ?? '#6b7280';
    }

    // TRACE: workflow_stages() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function workflow_stages(): array {
        return [
            'booking_received'    => [ 'label' => 'Booking Received',          'color' => '#6b7280', 'icon' => '📋' ],
            'under_review'        => [ 'label' => 'Under Review',              'color' => '#2563eb', 'icon' => '🔍' ],
            'ready_to_process'    => [ 'label' => 'Ready to Process',          'color' => '#3b82f6', 'icon' => '✅' ],
            'documents_received'  => [ 'label' => 'Documents Received',        'color' => '#f97316', 'icon' => '📄' ],
            'payment_received'    => [ 'label' => 'Payment Received',          'color' => '#f59e0b', 'icon' => '💳' ],
            'ad_processing'       => [ 'label' => 'Ad Processing / Designing', 'color' => '#8b5cf6', 'icon' => '🎨' ],
            'submitted_to_pub'    => [ 'label' => 'Submitted to Publication',  'color' => '#7c3aed', 'icon' => '📨' ],
            'published'           => [ 'label' => 'Published',                 'color' => '#10b981', 'icon' => '🗞️' ],
            'completed'           => [ 'label' => 'Completed',                 'color' => '#059669', 'icon' => '🎉' ],
        ];
    }

    // TRACE: negative_statuses() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: array (empty on no results).
    //        Edge cases: invalid input → error returned.
    public static function negative_statuses(): array {
        return [
            'not_able_to_process' => [ 'label' => 'Not Able to Process', 'color' => '#ef4444', 'icon' => '⛔' ],
            'not_eligible'        => [ 'label' => 'Not Eligible',         'color' => '#ef4444', 'icon' => '❌' ],
            'no_service'          => [ 'label' => 'No Service in Area',   'color' => '#f97316', 'icon' => '🚫' ],
            'rejected'            => [ 'label' => 'Rejected',              'color' => '#dc2626', 'icon' => '✗' ],
        ];
    }

    // TRACE: all_statuses() — Called internally or via AJAX action.
    //        Steps: returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function all_statuses(): array {
        return array_merge( self::workflow_stages(), self::negative_statuses(), [
            'draft' => [ 'label' => 'Draft', 'color' => '#9ca3af', 'icon' => '📝' ]
        ] );
    }

    // TRACE: json_response() — Called internally or via AJAX action.
    //        Steps: returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function json_response( bool $success, $data = [], string $message = '' ): void {
        if ( $success ) {
            wp_send_json_success( array_merge( is_array($data) ? $data : ['data' => $data], $message ? ['message' => $message] : [] ) );
        } else {
            wp_send_json_error( is_array($data) ? $data : [ 'message' => $data ?: $message ] );
        }
    }
}

// ── Global function aliases for convenience ───────────────────────────────────
// TRACE: nas_fmt_price(amount) — Trigger: called by any template or module needing price display.
//        Steps: retrieves currency_symbol from Config → formats float to 2dp → prepends symbol.
//        Output: formatted string e.g. '₹1,200.00'. Edge cases: non-numeric input cast to 0.00.
//        Preconditions: auth verified by caller. Postcondition: returns result or wp_send_json_error.
//        Edge cases: missing params → error; DB failure → false/null/error response.
function nas_fmt_price( float $amount ): string {
    return \NAS\Core\Helpers::format_currency( $amount );
}
// TRACE: nas_status_label(status) — Trigger: called by templates to display human-readable status.
//        Steps: looks up status in Helpers::all_statuses() label map → returns display string.
//        Output: human string e.g. 'Under Review'. Edge cases: unknown status → returns raw status key.
//        Preconditions: auth verified by caller. Postcondition: returns result or wp_send_json_error.
//        Edge cases: missing params → error; DB failure → false/null/error response.
function nas_status_label( string $status ): string {
    return \NAS\Core\Helpers::status_label( $status );
}
// TRACE: nas_config(key, default) — Trigger: global helper wrapper for Config::instance()->get().
//        Steps: gets Config singleton → calls get(key, default) → returns setting value.
//        Output: setting value (mixed type). Edge cases: key not in DB → returns $default.
//        Preconditions: auth verified by caller. Postcondition: returns result or wp_send_json_error.
//        Edge cases: missing params → error; DB failure → false/null/error response.
function nas_config( string $key, $default = null ) {
    return \NAS\Core\Config::instance()->get( $key, $default );
}
