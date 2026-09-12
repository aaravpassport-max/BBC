<?php
/**
 * Plugin deactivation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Deactivator {

	public static function deactivate() {
		Portmystuff_Cron::clear_events();
		flush_rewrite_rules();
	}
}
