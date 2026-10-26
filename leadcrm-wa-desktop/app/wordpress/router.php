<?php
/**
 * PHP built-in server router for LeadCRM WA desktop.
 * Clears stuck WordPress maintenance before bootstrap (mu-plugins load too late).
 */
$maintenance = __DIR__ . '/.maintenance';
if ( is_file( $maintenance ) ) {
    @unlink( $maintenance );
}

$uri  = urldecode( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) );
$file = __DIR__ . $uri;
if ( $uri !== '/' && is_file( $file ) ) {
    return false;
}
require __DIR__ . '/index.php';
