<?php
/**
 * InterviewAce PHPUnit Bootstrap
 * 
 * Loads WordPress test environment.
 * Run: vendor/bin/phpunit --testdox
 */

// Load WordPress test suite
$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';

if (!file_exists($_tests_dir . '/includes/functions.php')) {
    die("WordPress test library not found at: {$_tests_dir}\n"
      . "Run: bash bin/install-wp-tests.sh wordpress_test root '' localhost\n");
}

require_once $_tests_dir . '/includes/functions.php';

// Load the plugin
tests_add_filter('muplugins_loaded', function() {
    require dirname(__DIR__) . '/interviewace.php';
});

require $_tests_dir . '/includes/bootstrap.php';
