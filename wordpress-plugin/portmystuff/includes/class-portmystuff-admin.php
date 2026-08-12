<?php
/**
 * WordPress admin menus and dashboard pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting( 'portmystuff_settings', 'portmystuff_default_city' );
		register_setting( 'portmystuff_settings', 'portmystuff_demo_otp' );
	}

	public function register_menus() {
		add_menu_page(
			__( 'PORTMYSTUFF', 'portmystuff' ),
			__( 'PORTMYSTUFF', 'portmystuff' ),
			'pms_analytics_view',
			'portmystuff',
			array( $this, 'render_dashboard' ),
			'dashicons-car',
			3
		);

		$pages = array(
			'portmystuff'           => array( __( 'Dashboard', 'portmystuff' ), 'render_dashboard' ),
			'portmystuff-bookings'  => array( __( 'Bookings', 'portmystuff' ), 'render_bookings' ),
			'portmystuff-drivers'   => array( __( 'Drivers', 'portmystuff' ), 'render_drivers' ),
			'portmystuff-rate-cards'=> array( __( 'Rate Cards', 'portmystuff' ), 'render_rate_cards' ),
			'portmystuff-analytics' => array( __( 'Analytics', 'portmystuff' ), 'render_analytics' ),
			'portmystuff-kyc'       => array( __( 'KYC Review', 'portmystuff' ), 'render_kyc' ),
			'portmystuff-support'   => array( __( 'Support', 'portmystuff' ), 'render_support' ),
			'portmystuff-marketing' => array( __( 'Marketing', 'portmystuff' ), 'render_marketing' ),
			'portmystuff-settlement'=> array( __( 'Settlement', 'portmystuff' ), 'render_settlement' ),
			'portmystuff-ops-sos'   => array( __( 'SOS Queue', 'portmystuff' ), 'render_sos' ),
			'portmystuff-ops-dispatch' => array( __( 'Dispatch Monitor', 'portmystuff' ), 'render_dispatch' ),
			'portmystuff-ops-map'   => array( __( 'Live Map', 'portmystuff' ), 'render_live_map' ),
			'portmystuff-settings'  => array( __( 'Settings', 'portmystuff' ), 'render_settings' ),
		);

		foreach ( $pages as $slug => $meta ) {
			if ( 'portmystuff' === $slug ) {
				continue;
			}
			$cap = strpos( $slug, 'ops-' ) !== false ? 'pms_ops_sos_respond' : 'pms_analytics_view';
			add_submenu_page( 'portmystuff', $meta[0], $meta[0], $cap, $slug, array( $this, $meta[1] ) );
		}
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'portmystuff' ) === false ) {
			return;
		}
		wp_enqueue_style( 'portmystuff-admin', PORTMYSTUFF_PLUGIN_URL . 'assets/css/admin.css', array(), PORTMYSTUFF_VERSION );
		wp_enqueue_script( 'portmystuff-admin', PORTMYSTUFF_PLUGIN_URL . 'assets/js/admin.js', array(), PORTMYSTUFF_VERSION, true );
		wp_localize_script(
			'portmystuff-admin',
			'PORTMYSTUFF_ADMIN',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'portmystuff/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'pluginUrl' => PORTMYSTUFF_PLUGIN_URL,
			)
		);
	}

	private function wrap( $title, $template ) {
		echo '<div class="wrap portmystuff-admin">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		include PORTMYSTUFF_PLUGIN_DIR . 'admin/views/' . $template;
		echo '</div>';
	}

	public function render_dashboard() {
		$this->wrap( __( 'PORTMYSTUFF Dashboard', 'portmystuff' ), 'dashboard.php' );
	}

	public function render_bookings() {
		$this->wrap( __( 'Bookings', 'portmystuff' ), 'bookings.php' );
	}

	public function render_drivers() {
		$this->wrap( __( 'Drivers', 'portmystuff' ), 'drivers.php' );
	}

	public function render_rate_cards() {
		$this->wrap( __( 'Rate Cards', 'portmystuff' ), 'rate-cards.php' );
	}

	public function render_analytics() {
		$this->wrap( __( 'Analytics', 'portmystuff' ), 'analytics.php' );
	}

	public function render_kyc() {
		$this->wrap( __( 'KYC Review', 'portmystuff' ), 'kyc.php' );
	}

	public function render_support() {
		$this->wrap( __( 'Support Queue', 'portmystuff' ), 'support.php' );
	}

	public function render_marketing() {
		$this->wrap( __( 'Marketing Banners', 'portmystuff' ), 'marketing.php' );
	}

	public function render_settlement() {
		$this->wrap( __( 'Settlement & Payouts', 'portmystuff' ), 'settlement.php' );
	}

	public function render_sos() {
		$this->wrap( __( 'SOS Queue', 'portmystuff' ), 'sos.php' );
	}

	public function render_dispatch() {
		$this->wrap( __( 'Dispatch Monitor', 'portmystuff' ), 'dispatch.php' );
	}

	public function render_live_map() {
		$this->wrap( __( 'Live Driver Map', 'portmystuff' ), 'live-map.php' );
	}

	public function render_settings() {
		$this->wrap( __( 'Plugin Settings', 'portmystuff' ), 'settings.php' );
	}
}
