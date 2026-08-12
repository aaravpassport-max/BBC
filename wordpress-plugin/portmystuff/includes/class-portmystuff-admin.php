<?php
/**
 * Minimal WP admin entry — links to standalone apps only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting( 'portmystuff_settings', 'portmystuff_default_city' );
	}

	public function register_menus() {
		add_menu_page(
			__( 'PORTMYSTUFF Apps', 'portmystuff' ),
			__( 'PORTMYSTUFF', 'portmystuff' ),
			'read',
			'portmystuff',
			array( $this, 'render_launcher' ),
			'dashicons-car',
			58
		);
	}

	public function render_launcher() {
		$apps = array(
			array( 'label' => __( 'App Launcher', 'portmystuff' ), 'url' => Portmystuff_Router::app_url(), 'desc' => __( 'Choose customer, driver, admin, or ops app', 'portmystuff' ) ),
			array( 'label' => __( 'Customer App', 'portmystuff' ), 'url' => Portmystuff_Router::app_url( 'customer' ), 'desc' => __( 'Book rides & parcels', 'portmystuff' ) ),
			array( 'label' => __( 'Driver App', 'portmystuff' ), 'url' => Portmystuff_Router::app_url( 'driver' ), 'desc' => __( 'Partner dashboard', 'portmystuff' ) ),
		);

		if ( Portmystuff_Roles::user_can( 'pms_analytics_view' ) ) {
			$apps[] = array( 'label' => __( 'Admin Console', 'portmystuff' ), 'url' => Portmystuff_Router::app_url( 'admin' ), 'desc' => __( 'Business dashboards', 'portmystuff' ) );
		}
		if ( Portmystuff_Roles::user_can( 'pms_ops_sos_respond' ) ) {
			$apps[] = array( 'label' => __( 'Control Room', 'portmystuff' ), 'url' => Portmystuff_Router::app_url( 'ops' ), 'desc' => __( 'SOS & live map', 'portmystuff' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'PORTMYSTUFF Standalone Apps', 'portmystuff' ) . '</h1>';
		echo '<p>' . esc_html__( 'All apps open as independent full-screen experiences — not inside wp-admin.', 'portmystuff' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'App', 'portmystuff' ) . '</th><th>' . esc_html__( 'Description', 'portmystuff' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $apps as $app ) {
			printf(
				'<tr><td><strong>%s</strong></td><td>%s</td><td><a class="button button-primary" href="%s" target="_blank" rel="noopener">%s</a></td></tr>',
				esc_html( $app['label'] ),
				esc_html( $app['desc'] ),
				esc_url( $app['url'] ),
				esc_html__( 'Open app', 'portmystuff' )
			);
		}
		echo '</tbody></table>';
		echo '<p><code>' . esc_html( Portmystuff_Router::app_url() ) . '</code></p>';
		echo '<p class="description">' . esc_html__( 'After install, visit Permalinks → Save to refresh routes if apps 404.', 'portmystuff' ) . '</p>';
		echo '</div>';
	}
}
