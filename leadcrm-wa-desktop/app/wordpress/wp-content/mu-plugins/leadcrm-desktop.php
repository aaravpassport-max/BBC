<?php
/**
 * LeadCRM WA Desktop — local-only session (no wp-login / login wall on 127.0.0.1).
 */
if ( ! defined( 'LCRM_DESKTOP_APP' ) || ! LCRM_DESKTOP_APP ) {
    return;
}

function lcrm_desktop_is_local_request() {
    // Bundled PHP `-S` (desktop + CI audit).
    if ( PHP_SAPI === 'cli-server' ) {
        return true;
    }
    $software = $_SERVER['SERVER_SOFTWARE'] ?? '';
    if ( $software !== '' && str_contains( $software, 'Development Server' ) ) {
        return true;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ( in_array( $remote, [ '127.0.0.1', '::1' ], true ) ) {
        return true;
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return (bool) preg_match( '#^127\\.0\\.0\\.1(:\\d+)?$#', $host );
}

if ( ! lcrm_desktop_is_local_request() ) {
    return;
}

// Never show hosting-style maintenance on the offline desktop app.
add_filter( 'enable_maintenance_mode', '__return_false', 100 );

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
    $user = lcrm_desktop_local_user();
    if ( ! $user ) {
        return false;
    }
    if ( ! is_user_logged_in() || (int) get_current_user_id() !== (int) $user->ID ) {
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true, is_ssl() );
    }
    return true;
}

/** Cookie-less auth for Electron / PHP built-in server (determine_current_user runs before cookies). */
add_filter(
    'determine_current_user',
    function ( $user_id ) {
        if ( $user_id ) {
            return $user_id;
        }
        $user = lcrm_desktop_local_user();
        return $user ? (int) $user->ID : $user_id;
    },
    20
);

add_action(
    'init',
    function () {
        lcrm_desktop_sign_in_local_user();
        if ( function_exists( 'lcrm_register_roles' ) ) {
            lcrm_register_roles();
        }
    },
    0
);

add_action(
    'template_redirect',
    function () {
        if ( is_admin() ) {
            return;
        }
        lcrm_desktop_sign_in_local_user();
        $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        if ( $path === 'crm' || str_starts_with( $path, 'crm/' ) ) {
            if ( is_user_logged_in() ) {
                return;
            }
            wp_safe_redirect( home_url( '/leadcrm-desktop-bootstrap.php' ) );
            exit;
        }
    },
    0
);

add_action(
    'login_init',
    function () {
        if ( lcrm_desktop_sign_in_local_user() ) {
            wp_safe_redirect( home_url( '/crm/' ) );
            exit;
        }
    },
    0
);

add_filter(
    'authenticate',
    function ( $user ) {
        if ( $user instanceof WP_User ) {
            return $user;
        }
        $local = lcrm_desktop_local_user();
        return $local instanceof WP_User ? $local : $user;
    },
    100,
    1
);

add_filter(
    'login_redirect',
    function ( $redirect_to, $requested, $user ) {
        if ( $user instanceof WP_User && ! is_wp_error( $user ) ) {
            return home_url( '/crm/' );
        }
        return $redirect_to;
    },
    10,
    3
);
