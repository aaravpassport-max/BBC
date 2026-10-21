<?php
/**
 * LeadCRM WA Desktop — local app session (no WordPress login screen on this PC).
 */
if ( ! defined( 'LCRM_DESKTOP_APP' ) || ! LCRM_DESKTOP_APP ) {
    return;
}

add_action( 'init', function () {
    if ( is_user_logged_in() ) {
        return;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ( ! in_array( $remote, [ '127.0.0.1', '::1' ], true ) ) {
        return;
    }
    $user = get_user_by( 'login', 'admin' );
    if ( ! $user ) {
        $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] );
        $user   = $admins[0] ?? null;
    }
    if ( ! $user ) {
        return;
    }
    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, true, is_ssl() );
}, 1 );
