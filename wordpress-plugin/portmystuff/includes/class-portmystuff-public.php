<?php
/**
 * Public shortcodes redirect to standalone React app URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Public {

	public function __construct() {
		add_shortcode( 'portmystuff_customer', array( $this, 'customer_redirect' ) );
		add_shortcode( 'portmystuff_driver', array( $this, 'driver_redirect' ) );
		add_shortcode( 'portmystuff_book', array( $this, 'customer_redirect' ) );
		add_shortcode( 'portmystuff_ride', array( $this, 'customer_redirect' ) );
		add_shortcode( 'portmystuff_app', array( $this, 'launcher_redirect' ) );
	}

	public function launcher_redirect() {
		return $this->link_card( __( 'Open PORTMYSTUFF', 'portmystuff' ), Portmystuff_Router::app_url() );
	}

	public function customer_redirect() {
		return $this->link_card( __( 'Open Customer App', 'portmystuff' ), Portmystuff_Router::app_url( 'customer' ) );
	}

	public function driver_redirect() {
		return $this->link_card( __( 'Open Driver App', 'portmystuff' ), Portmystuff_Router::app_url( 'driver' ) );
	}

	private function link_card( $label, $url ) {
		return sprintf(
			'<div style="text-align:center;padding:24px;font-family:system-ui,sans-serif"><a href="%s" style="display:inline-block;background:#0d9f4f;color:#fff;padding:14px 24px;border-radius:12px;text-decoration:none;font-weight:600">%s</a></div>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
}
