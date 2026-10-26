<?php
/**
 * Deployed to WordPress root as leadcrm-desktop-bootstrap.php
 * One-shot local session for LeadCRM WA desktop (127.0.0.1 only).
 */
define( 'WP_USE_THEMES', false );
require __DIR__ . '/wp-load.php';

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if ( ! in_array( $remote, [ '127.0.0.1', '::1' ], true ) ) {
    status_header( 403 );
    exit( 'Forbidden' );
}

$user = get_user_by( 'login', 'admin' );
if ( ! $user ) {
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] );
    $user   = $admins[0] ?? null;
}
if ( ! $user ) {
    status_header( 503 );
    exit( 'LeadCRM is still setting up. Close the app, wait a minute, and open it again.' );
}

wp_clear_auth_cookie();
wp_set_current_user( $user->ID );
wp_set_auth_cookie( $user->ID, true, is_ssl() );

$path = '/crm/';
if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
    $scheme = is_ssl() ? 'https' : 'http';
    wp_safe_redirect( $scheme . '://' . $_SERVER['HTTP_HOST'] . $path );
} else {
    wp_safe_redirect( home_url( $path ) );
}
exit;
