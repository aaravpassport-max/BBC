<?php
/**
 * Guard against # delimiter bugs in path regexes (see SEO.php / Blog.php).
 */
$patterns = [
    '~^/service/([^/?#]+)~' => [ '/service/passport', true ],
    '~^/blog/[^/?#]+~'       => [ '/blog/my-post', true ],
    '~^/blog/([^/?#]+)~'     => [ '/blog/my-post', true ],
];

$fail = 0;
foreach ( $patterns as $pattern => [ $path, $expect ] ) {
    $ok = (bool) preg_match( $pattern, $path );
    if ( $ok !== $expect ) {
        fwrite( STDERR, "FAIL: {$pattern} on {$path}\n" );
        $fail++;
    }
}

// Document the broken form must never return true without warning.
$broken = '#^/service/([^/?#]+)#';
set_error_handler( static function () { return true; } );
$bad = @preg_match( $broken, '/service/x', $m );
restore_error_handler();
if ( $bad !== false && $bad !== 0 ) {
    fwrite( STDERR, "FAIL: broken # delimiter pattern unexpectedly matched\n" );
    $fail++;
}

exit( $fail > 0 ? 1 : 0 );
