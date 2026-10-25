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
		add_action( 'admin_init', array( $this, 'handle_flush' ) );
	}

	public function handle_flush() {
		if ( empty( $_GET['pms_flush'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'pms_flush_rewrites' );
		Portmystuff_Router::register_rewrites();
		Portmystuff_Router::ensure_app_page();
		flush_rewrite_rules( false );
		wp_safe_redirect( admin_url( 'admin.php?page=portmystuff&flushed=1' ) );
		exit;
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
		echo '<p class="description">' . esc_html__( 'If apps show your theme, go to Settings → Permalinks → Save, then reload.', 'portmystuff' ) . '</p>';
		if ( current_user_can( 'manage_options' ) ) {
			$flush_url = wp_nonce_url( admin_url( 'admin.php?page=portmystuff&pms_flush=1' ), 'pms_flush_rewrites' );
			echo '<p><a class="button" href="' . esc_url( $flush_url ) . '">' . esc_html__( 'Flush app routes', 'portmystuff' ) . '</a></p>';
		}
		echo '</div>';
	}
}
