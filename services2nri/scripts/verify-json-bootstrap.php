<?php
/**
 * Ensures SEO JSON bootstrap is parseable (esc_html on JSON breaks JSON.parse).
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', true );

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

$payload = [
    'platform_name' => 'Test & Co "Quotes"',
    'note'          => '</script><script>alert(1)</script>',
    'line'          => "before\u{2028}after",
];
$flags   = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
$json    = wp_json_encode( $payload, $flags );
$safe    = str_ireplace( '</script', '<\\/script', $json );

$broken = htmlspecialchars( $json, ENT_QUOTES, 'UTF-8' );
if ( json_decode( $broken, true ) !== null ) {
    fwrite( STDERR, "FAIL: expected esc_html-style JSON to be invalid\n" );
    exit( 1 );
}

$parsed = json_decode( $safe, true );
if ( ! is_array( $parsed ) || ( $parsed['platform_name'] ?? '' ) !== 'Test & Co "Quotes"' ) {
    fwrite( STDERR, "FAIL: safe JSON bootstrap not parseable\n" );
    exit( 1 );
}

echo "OK  json bootstrap parseable\n";
