<?php
/**
 * Public-facing shortcodes and app embeds.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Public {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'portmystuff_customer', array( $this, 'customer_app' ) );
		add_shortcode( 'portmystuff_driver', array( $this, 'driver_app' ) );
		add_shortcode( 'portmystuff_book', array( $this, 'customer_app' ) );
		add_shortcode( 'portmystuff_ride', array( $this, 'customer_app' ) );
	}

	public function register_assets() {
		wp_register_style( 'portmystuff-apps', PORTMYSTUFF_PLUGIN_URL . 'assets/css/apps.css', array(), PORTMYSTUFF_VERSION );
		wp_register_script( 'portmystuff-app-loader', PORTMYSTUFF_PLUGIN_URL . 'assets/js/app-loader.js', array(), PORTMYSTUFF_VERSION, true );
	}

	private function app_config( $app ) {
		return array(
			'app'       => $app,
			'apiBase'   => esc_url_raw( rest_url( 'portmystuff/v1' ) ),
			'adminBase' => esc_url_raw( rest_url( 'portmystuff/admin/v1' ) ),
			'opsBase'   => esc_url_raw( rest_url( 'portmystuff/ops/v1' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'assetsUrl' => PORTMYSTUFF_PLUGIN_URL . 'assets/apps/' . $app . '/',
		);
	}

	private function render_app_shell( $app, $title ) {
		wp_enqueue_style( 'portmystuff-apps' );
		wp_enqueue_script( 'portmystuff-app-loader' );
		wp_localize_script( 'portmystuff-app-loader', 'PORTMYSTUFF_CONFIG', $this->app_config( $app ) );

		ob_start();
		?>
		<div class="portmystuff-app-shell" data-app="<?php echo esc_attr( $app ); ?>">
			<div id="portmystuff-<?php echo esc_attr( $app ); ?>-root" class="portmystuff-app-root">
				<div class="portmystuff-loading"><?php echo esc_html( sprintf( __( 'Loading %s…', 'portmystuff' ), $title ) ); ?></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function customer_app() {
		return $this->render_app_shell( 'customer', __( 'PORTMYSTUFF Customer', 'portmystuff' ) );
	}

	public function driver_app() {
		return $this->render_app_shell( 'driver', __( 'PORTMYSTUFF Partner', 'portmystuff' ) );
	}
}
