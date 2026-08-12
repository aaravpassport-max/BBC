<?php
/**
 * Plugin activation — tables, roles, seed data, cron schedules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Activator {

	public static function activate() {
		Portmystuff_Database::create_tables();
		Portmystuff_Roles::install();
		Portmystuff_Seed::run();
		Portmystuff_Cron::schedule_events();
		flush_rewrite_rules();
		update_option( 'portmystuff_version', PORTMYSTUFF_VERSION );
	}
}
