<?php
/**
 * Blank template — prevents theme from wrapping the app.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Portmystuff_Router::instance()->render_standalone_shell();
exit;
