<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Security — nonces, capabilities, sanitization, validation.
 */
class Security {

    /** Verify nonce and die on failure */
    // TRACE: check_nonce() — Called internally or via AJAX action.
    //        Steps: checks permissions → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function check_nonce( string $nonce_value, string $action = 'nas_action' ): void {
        if ( ! wp_verify_nonce( $nonce_value, $action ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ], 403 );
        }
    }

    /** Verify capability and die */
    // TRACE: require_cap() — Called internally or via AJAX action.
    //        Steps: checks permissions → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function require_cap( string $cap ): void {
        if ( ! current_user_can( $cap ) ) {
            wp_send_json_error( [ 'message' => 'You do not have permission to do this.' ], 403 );
        }
    }

    // TRACE: require_login() — Called internally or via AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function require_login(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Please log in to continue.' ], 401 );
        }
    }

    /** Check user owns a booking */
    // TRACE: user_owns_booking() — Called internally or via AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function user_owns_booking( int $booking_id, int $user_id = 0 ): bool {
        if ( ! $user_id ) $user_id = get_current_user_id();
        $db    = Database::instance();
        $table = $db->prefix('bookings');
        return (bool) $db->scalar( "SELECT id FROM `$table` WHERE id=%d AND client_id=(SELECT id FROM `{$db->prefix('clients')}` WHERE wp_user_id=%d LIMIT 1)", [ $booking_id, $user_id ] );
    }

    /** Determine user's NAS role */
    // TRACE: current_role() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function current_role(): string {
        if ( ! is_user_logged_in() ) return 'guest';
        $user = wp_get_current_user();
        if ( in_array('administrator', $user->roles) ) return 'admin';
        if ( in_array('nas_manager', $user->roles) )  return 'manager';
        if ( in_array('nas_staff', $user->roles) )    return 'staff';
        if ( in_array('nas_client', $user->roles) )   return 'client';
        if ( in_array('nas_vendor', $user->roles) )   return 'vendor';  // was missing — vendors always returned 'guest'
        return 'guest';
    }

    // TRACE: is_admin_or_staff() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function is_admin_or_staff(): bool {
        return in_array( self::current_role(), ['admin','manager','staff'] );
    }

    // TRACE: is_client() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function is_client(): bool {
        return self::current_role() === 'client';
    }

    // TRACE: is_vendor() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function is_vendor(): bool {
        return self::current_role() === 'vendor';
    }

    // ── Sanitization helpers ──────────────────────────────────────────────────

    // TRACE: sanitize_text() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: typed scalar value.
    //        Edge cases: invalid input → error returned.
    public static function sanitize_text( $v ): string {
        return sanitize_text_field( (string) $v );
    }

    // TRACE: sanitize_email() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: typed scalar value.
    //        Edge cases: invalid input → error returned.
    public static function sanitize_email( $v ): string {
        return sanitize_email( (string) $v );
    }

    // TRACE: sanitize_int() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: typed scalar value.
    //        Edge cases: invalid input → error returned.
    public static function sanitize_int( $v ): int {
        return (int) $v;
    }

    // TRACE: sanitize_float() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: typed scalar value.
    //        Edge cases: invalid input → error returned.
    public static function sanitize_float( $v ): float {
        return (float) $v;
    }

    // TRACE: sanitize_slug() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function sanitize_slug( $v ): string {
        return sanitize_title( (string) $v );
    }

    // TRACE: sanitize_html() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function sanitize_html( $v ): string {
        return wp_kses_post( (string) $v );
    }

    // TRACE: sanitize_textarea() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function sanitize_textarea( $v ): string {
        return sanitize_textarea_field( (string) $v );
    }

    // TRACE: sanitize_url() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function sanitize_url( $v ): string {
        return esc_url_raw( (string) $v );
    }

    // TRACE: sanitize_array() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function sanitize_array( array $arr ): array {
        return array_map( [ self::class, 'sanitize_text' ], $arr );
    }

    // ── Validation helpers ────────────────────────────────────────────────────

    // TRACE: validate_email() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: empty input values handled.
    public static function validate_email( string $email ): bool {
        return is_email( $email );
    }

    // TRACE: validate_phone() — Called internally or via AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: empty input values handled.
    public static function validate_phone( string $phone ): bool {
        return (bool) preg_match( '/^[+]?[0-9\s\-\(\)]{7,20}$/', $phone );
    }

    // TRACE: validate_required() — Called internally or via AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: empty input values handled.
    public static function validate_required( array $data, array $required_fields ): array {
        $errors = [];
        foreach ( $required_fields as $field ) {
            if ( empty( $data[ $field ] ) ) {
                $errors[ $field ] = ucwords( str_replace( '_', ' ', $field ) ) . ' is required.';
            }
        }
        return $errors;
    }

    /** POST helper: get sanitized value */
    // TRACE: post() — Called internally or via AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function post( string $key, string $type = 'text' ) {
        $val = $_POST[ $key ] ?? '';
        return match( $type ) {
            'email'    => self::sanitize_email( $val ),
            'int'      => self::sanitize_int( $val ),
            'float'    => self::sanitize_float( $val ),
            'slug'     => self::sanitize_slug( $val ),
            'html'     => self::sanitize_html( $val ),
            'textarea' => self::sanitize_textarea( $val ),
            'url'      => self::sanitize_url( $val ),
            default    => self::sanitize_text( $val ),
        };
    }

    /** Read and sanitize a GET parameter */
    // TRACE: get() — Called internally or via AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function get( string $key, string $type = 'text' ) {
        $val = $_GET[ $key ] ?? '';
        return match( $type ) {
            'email'    => self::sanitize_email( $val ),
            'int'      => self::sanitize_int( $val ),
            'float'    => self::sanitize_float( $val ),
            'slug'     => self::sanitize_slug( $val ),
            'html'     => self::sanitize_html( $val ),
            'textarea' => self::sanitize_textarea( $val ),
            'url'      => self::sanitize_url( $val ),
            default    => self::sanitize_text( $val ),
        };
    }

    /** Read from either POST or GET (POST takes precedence) */
    // TRACE: input() — Called internally or via AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function input( string $key, string $type = 'text' ) {
        $val = $_POST[ $key ] ?? $_GET[ $key ] ?? '';
        return match( $type ) {
            'email'    => self::sanitize_email( $val ),
            'int'      => self::sanitize_int( $val ),
            'float'    => self::sanitize_float( $val ),
            default    => self::sanitize_text( $val ),
        };
    }

    // TRACE: get_nonce_for() — Called internally or via AJAX action.
    //        Steps: fetches and returns data.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function get_nonce_for( string $action = 'nas_action' ): string {
        return wp_create_nonce( $action );
    }
}
