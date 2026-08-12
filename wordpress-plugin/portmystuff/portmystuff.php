<?php
/**
 * Plugin Name:       PORTMYSTUFF Logistics Platform
 * Plugin URI:        https://portmystuff.com
 * Description:       Standalone ride + parcel logistics platform — customer booking, driver partner app, admin console, and ops control room. No external backend required.
 * Version:           1.2.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            PORTMYSTUFF
 * License:           GPL v2 or later
 * Text Domain:       portmystuff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PORTMYSTUFF_VERSION', '1.2.1' );
define( 'PORTMYSTUFF_PLUGIN_FILE', __FILE__ );
define( 'PORTMYSTUFF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PORTMYSTUFF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PORTMYSTUFF_PLUGIN_DIR . 'includes/class-portmystuff-autoloader.php';
Portmystuff_Autoloader::register();

require_once PORTMYSTUFF_PLUGIN_DIR . 'includes/class-portmystuff.php';

/**
 * Returns the main plugin instance.
 *
 * @return Portmystuff
 */
function portmystuff() {
	return Portmystuff::instance();
}

register_activation_hook( __FILE__, array( 'Portmystuff_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Portmystuff_Deactivator', 'deactivate' ) );

portmystuff();
