<?php
/**
 * Main plugin bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	private function load_dependencies() {
		new Portmystuff_Router();
		new Portmystuff_Cron();
		new Portmystuff_Admin();
		new Portmystuff_Public();
		new Portmystuff_Roles();
	}

	private function init_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'portmystuff', false, dirname( plugin_basename( PORTMYSTUFF_PLUGIN_FILE ) ) . '/languages' );
	}

	public function register_rest_routes() {
		Portmystuff_Rest_Api::register_routes();
	}
}
