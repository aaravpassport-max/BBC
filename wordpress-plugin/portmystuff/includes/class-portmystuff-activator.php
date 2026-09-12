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
		Portmystuff_Router::register_rewrites();
		Portmystuff_Router::ensure_app_page();
		flush_rewrite_rules( false );
		update_option( 'portmystuff_version', PORTMYSTUFF_VERSION );
		update_option( 'portmystuff_flush_rewrite', PORTMYSTUFF_VERSION );
	}
}
