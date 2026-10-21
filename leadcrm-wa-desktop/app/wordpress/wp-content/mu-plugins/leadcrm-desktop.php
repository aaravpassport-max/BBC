<?php
/**
 * LeadCRM WA Desktop — keep local CRM reachable without wp-login.php.
 */
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if ( ! in_array( $remote, [ '127.0.0.1', '::1' ], true ) ) {
    return;
}

function lcrm_desktop_local_user() {
    static $user = null;
    if ( $user !== null ) {
        return $user;
    }
    $user = get_user_by( 'login', 'admin' );
    if ( ! $user ) {
        $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] );
        $user   = $admins[0] ?? false;
    }
    return $user ?: null;
}

function lcrm_desktop_sign_in_local_user() {
    if ( is_user_logged_in() ) {
        return true;
    }
    $user = lcrm_desktop_local_user();
    if ( ! $user ) {
        return false;
    }
    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, true, is_ssl() );
    return true;
}

add_action( 'init', 'lcrm_desktop_sign_in_local_user', 0 );

add_action( 'login_init', function () {
    if ( lcrm_desktop_sign_in_local_user() ) {
        wp_safe_redirect( home_url( '/crm/' ) );
        exit;
    }
}, 0 );

add_filter( 'authenticate', function ( $user ) {
    if ( $user instanceof WP_User ) {
        return $user;
    }
    if ( lcrm_desktop_sign_in_local_user() ) {
        return lcrm_desktop_local_user();
    }
    return $user;
}, 100, 1 );
