<?php
/**
 * PSR-4 style autoloader for plugin classes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Autoloader {

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	public static function autoload( $class ) {
		if ( strpos( $class, 'Portmystuff_' ) !== 0 ) {
			return;
		}

		$relative = strtolower( str_replace( '_', '-', substr( $class, strlen( 'Portmystuff_' ) ) ) );
		$file     = PORTMYSTUFF_PLUGIN_DIR . 'includes/class-portmystuff-' . $relative . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
