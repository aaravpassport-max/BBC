<?php
/**
 * WP-Cron background jobs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Cron {

	const HOOK_DISPATCH = 'portmystuff_dispatch_sweep';

	public function __construct() {
		add_action( self::HOOK_DISPATCH, array( 'Portmystuff_Dispatch_Service', 'expire_offers' ) );
	}

	public static function schedule_events() {
		if ( ! wp_next_scheduled( self::HOOK_DISPATCH ) ) {
			wp_schedule_event( time(), 'five_minutes', self::HOOK_DISPATCH );
		}
	}

	public static function clear_events() {
		wp_clear_scheduled_hook( self::HOOK_DISPATCH );
	}
}

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes', 'portmystuff' ),
		);
		return $schedules;
	}
);
